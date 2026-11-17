<?php

namespace App\Http\Requests;

use App\Billing\CostRange;
use App\Billing\PriceEstimator;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same rules Pixelforge applies, so a request we accept is one it accepts. The
 * allowed sizes and qualities come from its pricing table rather than a hardcoded list.
 */
class GenerationRequest extends FormRequest
{
    public function rules(PixelforgeClient $pixelforge): array
    {
        $outputTokens = $pixelforge->pricing()['tokens']['output'];
        $qualities = array_keys($outputTokens);
        $sizes = array_keys(reset($outputTokens));

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($sizes)],
            'quality' => ['required', Rule::in($qualities)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.*.required' => 'Enter an image URL or remove this field.',
            'reference_images.*.url' => 'Each reference image must be an http or https URL.',
        ];
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values($this->validated('reference_images', []));
    }

    public function estimate(PriceEstimator $estimator): CostRange
    {
        return $estimator->estimate(
            $this->validated('prompt'),
            $this->validated('size'),
            $this->validated('quality'),
            count($this->referenceImages()),
        );
    }
}
