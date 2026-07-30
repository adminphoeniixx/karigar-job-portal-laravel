<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResumeUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // PDF only: the parser has no other format, and 4 MB is generous for
            // a resume while keeping a padded upload from filling the disk.
            'resume' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resume.mimes' => __('Please upload your resume as a PDF.'),
            'resume.mimetypes' => __('Please upload your resume as a PDF.'),
            'resume.max' => __('Your resume must be smaller than 4 MB.'),
        ];
    }
}
