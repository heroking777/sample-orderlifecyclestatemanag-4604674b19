<?php
declare(strict_types=1);

/**
 * 発注ライフサイクル（依頼 → 発注 → 納品 → 請求 → 照合）を状態として管理する
 * 小さなサンプル。不正な状態遷移・二重登録・数量や金額の不一致を拒否できることを確認する。
 * 時刻・DB・フレームワークに依存せず、すべて決定的に検証できる。
 */

final class OrderException extends RuntimeException
{
}

final class OrderLedger
{
    public const REQUESTED = 'REQUESTED';
    public const ORDERED = 'ORDERED';
    public const DELIVERED = 'DELIVERED';
    public const INVOICED = 'INVOICED';
    public const RECONCILED = 'RECONCILED';

    /** @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** @var array<string, string> */
    private array $requestKeys = [];

    private int $seq = 0;

    public function request(string $key, string $material, int $qty, string $requester): string
    {
        if ($key === '' || $requester === '') {
            throw new OrderException('invalid_input');
        }
        if ($material === '') {
            throw new OrderException('invalid_material');
        }
        if ($qty <= 0) {
            throw new OrderException('invalid_quantity');
        }
        if (isset($this->requestKeys[$key])) {
            throw new OrderException('duplicate_request');
        }
        $this->seq++;
        $id = 'ord-' . $this->seq;
        $this->orders[$id] = [
            'state' => self::REQUESTED,
            'material' => $material,
            'qty' => $qty,
            'requester' => $requester,
            'received' => 0,
            'invoice' => null,
        ];
        $this->requestKeys[$key] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function must(string $id, string $state): array
    {
        if (!isset($this->orders[$id])) {
            throw new OrderException('unknown_order');
        }
        if ($this->orders[$id]['state'] !== $state) {
            throw new OrderException('invalid_transition');
        }
        return $this->orders[$id];
    }

    /** @return array<string, mixed> */
    public function order(string $id, string $vendor): array
    {
        $this->must($id, self::REQUESTED);
        if ($vendor === '') {
            throw new OrderException('invalid_vendor');
        }
        $this->orders[$id]['state'] = self::ORDERED;
        $this->orders[$id]['vendor'] = $vendor;
        return ['state' => self::ORDERED];
    }

    /** @return array<string, mixed> */
    public function deliver(string $id, int $receivedQty): array
    {
        $order = $this->must($id, self::ORDERED);
        if ($receivedQty !== $order['qty']) {
            throw new OrderException('quantity_mismatch');
        }
        $this->orders[$id]['state'] = self::DELIVERED;
        $this->orders[$id]['received'] = $receivedQty;
        return ['state' => self::DELIVERED, 'received' => $receivedQty];
    }

    /** @return array<string, mixed> */
    public function invoice(string $id, int $amount): array
    {
        $this->must($id, self::DELIVERED);
        if ($amount <= 0) {
            throw new OrderException('invalid_amount');
        }
        $this->orders[$id]['state'] = self::INVOICED;
        $this->orders[$id]['invoice'] = $amount;
        return ['state' => self::INVOICED, 'amount' => $amount];
    }

    /** @return array<string, mixed> */
    public function reconcile(string $id, int $invoiceAmount): array
    {
        $order = $this->must($id, self::INVOICED);
        if ($invoiceAmount !== $order['invoice']) {
            throw new OrderException('amount_mismatch');
        }
        $this->orders[$id]['state'] = self::RECONCILED;
        return ['state' => self::RECONCILED];
    }

    public function state(string $id): string
    {
        if (!isset($this->orders[$id])) {
            throw new OrderException('unknown_order');
        }
        return $this->orders[$id]['state'];
    }
}
