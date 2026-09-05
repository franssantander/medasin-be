<?php

namespace App\Http\Controllers\Resource;

use App\Data\Resource\ListResourceData;
use App\Data\Resource\StoreResourceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resource\ListResourceRequest;
use App\Http\Requests\Resource\StoreResourceAttachmentRequest;
use App\Http\Requests\Resource\StoreResourceRequest;
use App\Http\Requests\Resource\UpdateResourceRequest;
use App\Models\Resource;
use App\Services\Resource\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ResourceController extends Controller
{
    public function __construct(private ResourceService $service) {}

    public function index(ListResourceRequest $request): JsonResponse
    {
        return $this->success($this->service->listing($request->user(), ListResourceData::from($request->validated())));
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        return $this->success($this->service->create($request->user(), StoreResourceData::from($request->validated())), 'Successfully created resource.', 201);
    }

    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        $resource = $request->user()->resources()->whereKey($resource->getKey())->whereNull('archived_at')->firstOrFail();

        return $this->success($this->service->update($request->user(), $resource, $request->validated()), 'Successfully updated resource.');
    }

    public function storeAttachment(StoreResourceAttachmentRequest $request, Resource $resource): JsonResponse
    {
        $resource = $request->user()->resources()->whereKey($resource->getKey())->whereNull('archived_at')->firstOrFail();

        return $this->success($this->service->addAttachments($resource, $request->validated('links', []), $request->validated('files', [])), 'Successfully added attachment.');
    }

    public function destroyAttachment(Request $request, Resource $resource, string $attachment): JsonResponse
    {
        $resource = $request->user()->resources()->whereKey($resource->getKey())->whereNull('archived_at')->firstOrFail();

        return $this->success($this->service->deleteAttachment($resource, $attachment), 'Successfully removed attachment.');
    }

    public function tags(Request $request): JsonResponse
    {
        return $this->success($this->service->tags($request->user()));
    }

    public function archive(Request $request, Resource $resource): JsonResponse
    {
        $resource = $request->user()->resources()->whereKey($resource->getKey())->firstOrFail();

        if ($resource->archived_at === null) {
            $resource->forceFill(['archived_at' => now()])->save();
        }

        return $this->success(
            $this->service->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas'])),
            'Successfully archived resource.',
        );
    }

    public function restore(Request $request, Resource $resource): JsonResponse
    {
        $resource = $request->user()->resources()->whereKey($resource->getKey())->firstOrFail();

        if ($resource->archived_at !== null) {
            $resource->forceFill(['archived_at' => null])->save();
        }

        return $this->success(
            $this->service->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas'])),
            'Successfully restored resource.',
        );
    }

    public function attachment(Request $request, Resource $resource, string $attachment)
    {
        abort_unless($resource->user_id === $request->user()->id, 404);
        $file = $resource->attachments()->where('uuid', $attachment)->whereNotNull('path')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
