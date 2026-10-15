<?php

namespace App\Http\Requests;

use App\Services\ReceiptStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 能不能往这张单传收据，由 controller 调 policy 判断
    }

    public function rules(): array
    {
        // 单独上传收据的路由必须带文件；新建报销单时收据可以之后再补
        $required = $this->routeIs('claims.receipts.store') ? 'required' : 'nullable';

        return [
            'receipts' => [$required, 'array', 'max:'.config('claims.upload.max_files')],
            'receipts.*' => [
                'file',
                'mimetypes:'.implode(',', config('claims.upload.mimes')),
                'extensions:'.implode(',', config('claims.upload.extensions')),
                'max:'.config('claims.upload.max_kb'),
                // 光看扩展名和 MIME 不够，再检查实际内容：图片要能解析，PDF 要以 %PDF- 开头
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
