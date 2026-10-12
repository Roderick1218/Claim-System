<?php

namespace App\Http\Requests;

use App\Services\ReceiptStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // claim-level authorization is done in the controller via the policy
    }

    public function rules(): array
    {
        $required = $this->routeIs('claims.receipts.store') ? 'required' : 'nullable';

        return [
            'receipts' => [$required, 'array', 'max:'.config('claims.upload.max_files')],
            'receipts.*' => [
                'file',
                'mimetypes:'.implode(',', config('claims.upload.mimes')),
                'extensions:'.implode(',', config('claims.upload.extensions')),
                'max:'.config('claims.upload.max_kb'),
                // Real content check: the image must decode / the PDF must start with %PDF-.
                function (string $attribute, mixed $file, \Closure $fail) {
                    if ($file instanceof UploadedFile && $file->isValid()
                        && ! app(ReceiptStorageService::class)->contentLooksValid($file->getRealPath(), (string) $file->getMimeType())) {
                        $fail('The file "'.$file->getClientOriginalName().'" is not a valid JPG, PNG or PDF.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'receipts.*.mimetypes' => 'Only JPG, PNG or PDF receipts are allowed.',
            'receipts.*.extensions' => 'Only JPG, PNG or PDF receipts are allowed.',
            'receipts.*.max' => 'Each receipt must be at most '.(config('claims.upload.max_kb') / 1024).' MB.',
            'receipts.max' => 'You can upload at most '.config('claims.upload.max_files').' files at a time.',
        ];
    }
}
