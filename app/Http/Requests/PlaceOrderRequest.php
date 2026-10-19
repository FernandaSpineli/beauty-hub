<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlaceOrderRequest extends FormRequest
{
    public const array STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    protected function prepareForValidation(): void
    {
        $address = $this->input('shipping_address');
        if (is_array($address) && is_string($address['postal_code'] ?? null)) {
            $digits = preg_replace('/\D/', '', $address['postal_code']) ?? '';
            $address['postal_code'] = strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : $address['postal_code'];
            $this->merge(['shipping_address' => $address]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_address' => ['required', 'array:postal_code,street,number,complement,district,city,state'],
            'shipping_address.postal_code' => ['required', 'string', 'regex:/\A\d{5}-\d{3}\z/'],
            'shipping_address.street' => ['required', 'string', 'max:120'],
            'shipping_address.number' => ['required', 'string', 'max:10'],
            'shipping_address.complement' => ['sometimes', 'nullable', 'string', 'max:60'],
            'shipping_address.district' => ['required', 'string', 'max:60'],
            'shipping_address.city' => ['required', 'string', 'max:60'],
            'shipping_address.state' => ['required', Rule::in(self::STATES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function address(): array
    {
        /** @var array<string, string|null> $address */
        $address = $this->validated('shipping_address');

        return array_map(fn (?string $v): string => trim((string) $v), $address);
    }
}
