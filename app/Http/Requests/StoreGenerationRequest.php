<?php

namespace App\Http\Requests;

use App\Billing\Quality;
use App\Billing\Size;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $images = $this->input('reference_images', []);

        if (! is_array($images)) {
            return;
        }

        $this->merge([
            'reference_images' => array_values(array_filter(
                $images,
                fn (mixed $url): bool => is_string($url) && trim($url) !== '',
            )),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', Rule::url()->protocols(['http', 'https'])],
        ];
    }
}
