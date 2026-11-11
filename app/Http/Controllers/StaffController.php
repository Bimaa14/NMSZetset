<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    private function input(Request $request, ?User $staff = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($staff?->id)],
            'role' => 'required|in:noc,cs,teknisi',
            'password' => [$staff ? 'nullable' : 'required', 'string', 'min:12', 'max:128'],
        ]);
    }

    public function store(Request $request, AuditRecorder $audit)
    {
        Gate::authorize('manage-staff'); $input = $this->input($request);
        DB::transaction(function () use ($request, $input, $audit) {
            $staff = User::create($input + ['tenant_id' => $request->user()->tenant_id]);
            $audit->record($request, 'staff.create', $staff->email.' · '.$staff->role);
        });
        return response()->json(['message' => 'Staff berhasil ditambahkan.'], 201);
    }

    public function update(Request $request, int $id, AuditRecorder $audit)
    {
        Gate::authorize('manage-staff');
        $staff = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_if($staff->role === 'isp_owner' || $staff->id === $request->user()->id, 403, 'Akun Owner tidak dapat diubah melalui pengaturan Staff.');
        $input = $this->input($request, $staff);
        if (empty($input['password'])) unset($input['password']);
        DB::transaction(function () use ($request, $staff, $input, $audit) {
            $oldRole = $staff->role; $staff->update($input);
            $audit->record($request, 'staff.update', $staff->email.' · '.$oldRole.' → '.$staff->role);
        });
        return response()->json(['message' => 'Akun dan role staff berhasil diperbarui.']);
    }

    public function destroy(Request $request, int $id, AuditRecorder $audit)
    {
        Gate::authorize('manage-staff');
        $staff = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_if($staff->role === 'isp_owner' || $staff->id === $request->user()->id, 403, 'Akun Owner tidak dapat dihapus melalui pengaturan Staff.');
        DB::transaction(function () use ($request, $staff, $audit) {
            $audit->record($request, 'staff.delete', $staff->email.' · '.$staff->role); $staff->delete();
        });
        return response()->json(['message' => 'Staff dihapus. Akun tidak dapat mengakses workspace lagi.']);
    }
}
