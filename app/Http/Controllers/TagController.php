<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    private function input(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'tag_name' => ['required', 'string', 'max:50', Rule::unique('tags')->where('tenant_id', $request->user()->tenant_id)->ignore($tag?->id)],
            'color_hex' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }

    public function store(Request $request, AuditRecorder $audit)
    {
        Gate::authorize('manage-tags'); $input = $this->input($request);
        DB::transaction(function () use ($request, $input, $audit) {
            Tag::create($input + ['tenant_id' => $request->user()->tenant_id]);
            $audit->record($request, 'tag.create', $input['tag_name']);
        });
        return response()->json(['message' => 'Tag berhasil ditambahkan.'], 201);
    }

    public function update(Request $request, int $id, AuditRecorder $audit)
    {
        Gate::authorize('manage-tags');
        $tag = Tag::where('tenant_id', $request->user()->tenant_id)->findOrFail($id); $input = $this->input($request, $tag);
        DB::transaction(function () use ($request, $tag, $input, $audit) {
            $tag->update($input); $audit->record($request, 'tag.update', $tag->tag_name);
        });
        return response()->json(['message' => 'Tag berhasil diperbarui.']);
    }

    public function destroy(Request $request, int $id, AuditRecorder $audit)
    {
        Gate::authorize('manage-tags'); $tag = Tag::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        DB::transaction(function () use ($request, $tag, $audit) {
            $audit->record($request, 'tag.delete', $tag->tag_name); $tag->delete();
        });
        return response()->json(['message' => 'Tag dihapus. Data ONU tetap tersimpan.']);
    }
}
