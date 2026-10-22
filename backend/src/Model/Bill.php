<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class Bill
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public string $billId,
        public ?string $billDate,
        public float $payment,
        public float $totalPayment,
        public string $currency,
        public string $payType,
        public string $payStatus,
        public array $items,
    ) {
    }

    /**
     * @param array<string, mixed> $bill
     */
    public static function fromApi(array $bill): self
    {
        $items = $bill['items'] ?? [];

        return new self(
            billId: (string) ($bill['bill_id'] ?? ''),
            billDate: isset($bill['bill_date']) ? (string) $bill['bill_date'] : null,
            payment: (float) ($bill['payment'] ?? 0),
            totalPayment: (float) ($bill['total_payment'] ?? $bill['payment'] ?? 0),
            currency: (string) ($bill['currency'] ?? 'RUR'),
            payType: (string) ($bill['pay_type'] ?? ''),
            payStatus: (string) ($bill['pay_status'] ?? ''),
            items: is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
        );
    }

    public function isPaid(): bool
    {
        return $this->payStatus === 'payed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bill_id' => $this->billId,
            'bill_date' => $this->billDate,
            'payment' => $this->payment,
            'total_payment' => $this->totalPayment,
            'currency' => $this->currency,
            'pay_type' => $this->payType,
            'pay_status' => $this->payStatus,
            'paid' => $this->isPaid(),
            'items' => $this->items,
        ];
    }
}
