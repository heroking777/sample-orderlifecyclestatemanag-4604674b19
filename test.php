<?php
declare(strict_types=1);
require __DIR__ . '/order.php';

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "ok - {$label}\n";
    } else {
        $failures++;
        fwrite(STDERR, "not ok - {$label}\n");
    }
}

function expect_fail(callable $fn, string $code): bool
{
    try {
        $fn();
        return false;
    } catch (OrderException $e) {
        return $e->getMessage() === $code;
    }
}

$ledger = new OrderLedger();
$id = $ledger->request('rk-1', '六角ボルト M8', 100, '現場A');
check($ledger->state($id) === OrderLedger::REQUESTED, '依頼直後は REQUESTED');
check(expect_fail(fn () => $ledger->request('rk-1', '六角ボルト M8', 100, '現場A'), 'duplicate_request'),
    '同じ依頼キーの二重登録は拒否');
check(expect_fail(fn () => $ledger->request('rk-2', 'ナット', 0, '現場A'), 'invalid_quantity'),
    '数量が0以下なら拒否');
check(expect_fail(fn () => $ledger->request('rk-3', '', 10, '現場A'), 'invalid_material'),
    '品名が空なら拒否');
check($ledger->order($id, '資材商事')['state'] === OrderLedger::ORDERED, 'REQUESTED から ORDERED は許可');
$other = $ledger->request('rk-4', 'ワッシャー', 50, '現場B');
check(expect_fail(fn () => $ledger->deliver($other, 50), 'invalid_transition'),
    '発注前の納品は拒否');
check(expect_fail(fn () => $ledger->deliver($id, 99), 'quantity_mismatch'),
    '納品数量が発注数と違えば拒否');
$delivered = $ledger->deliver($id, 100);
check($delivered['received'] === 100 && $ledger->state($id) === OrderLedger::DELIVERED,
    'ORDERED から DELIVERED で受領数が確定');
check(expect_fail(fn () => $ledger->invoice($other, 5000), 'invalid_transition'),
    '未納品の請求は拒否');
$invoiced = $ledger->invoice($id, 12000);
check($invoiced['amount'] === 12000 && $ledger->state($id) === OrderLedger::INVOICED,
    'DELIVERED から INVOICED で請求額が確定');
check(expect_fail(fn () => $ledger->reconcile($id, 11999), 'amount_mismatch'),
    '請求額と照合額が一致しなければ拒否');
check($ledger->reconcile($id, 12000)['state'] === OrderLedger::RECONCILED,
    'INVOICED から RECONCILED は許可');
check(expect_fail(fn () => $ledger->state('ord-999'), 'unknown_order'),
    '存在しない注文IDは拒否');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}
echo "all 13 checks passed\n";
