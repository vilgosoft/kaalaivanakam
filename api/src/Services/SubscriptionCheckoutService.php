<?php

declare(strict_types=1);

namespace Paperkaaran\Services;

use DateTimeImmutable;
use PDO;
use Paperkaaran\Db\Database;
use RuntimeException;

final class SubscriptionCheckoutService
{
    public function getAgentIdForUser(int $userId): ?int
    {
        $pdo = Database::pdo();

        return $this->resolveAgentIdForCustomer($pdo, $userId);
    }

    /**
     * Prefer `users.assigned_agent_id` when set and active; else first active agent for `area_id`.
     */
    private function resolveAgentIdForCustomer(PDO $pdo, int $userId): ?int
    {
        $stmtUser = $pdo->prepare(
            'SELECT area_id, assigned_agent_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmtUser->execute(['id' => $userId]);
        $user = $stmtUser->fetch();
        if ($user === false) {
            return null;
        }
        $assigned = $user['assigned_agent_id'] !== null ? (int) $user['assigned_agent_id'] : 0;
        if ($assigned > 0) {
            $ag = $pdo->prepare('SELECT id FROM agents WHERE id = :id AND status = :st LIMIT 1');
            $ag->execute(['id' => $assigned, 'st' => 'active']);
            if ($ag->fetch() !== false) {
                return $assigned;
            }

            return null;
        }
        $areaId = $user['area_id'] !== null ? (int) $user['area_id'] : null;
        if ($areaId === null || $areaId <= 0) {
            return null;
        }

        return $this->resolveAgentIdForArea($pdo, $areaId);
    }

    /**
     * @param list<array<string, mixed>> $items newspaper: product_id, quantity, start_date, end_date — book/magazine: product_id, quantity, months (>=1)
     * @return array{subscription_id: int, total_amount: string, currency: string, mock_payment_gateway_order_id: string, items: list<array<string, mixed>>}
     */
    public function checkout(int $userId, array $items): array
    {
        if ($items === []) {
            throw new RuntimeException('At least one item is required');
        }

        $pdo = Database::pdo();
        $stmtUser = $pdo->prepare(
            'SELECT id, area_id, assigned_agent_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmtUser->execute(['id' => $userId]);
        $user = $stmtUser->fetch();
        if ($user === false) {
            throw new RuntimeException('User not found');
        }
        $areaId = $user['area_id'] !== null ? (int) $user['area_id'] : null;
        if ($areaId === null || $areaId <= 0) {
            throw new RuntimeException('User must complete profile with a service area before checkout');
        }

        $agentId = $this->resolveAgentIdForCustomer($pdo, $userId);
        if ($agentId === null) {
            throw new RuntimeException('No active delivery agent is assigned to your account');
        }
        $assignedRaw = $user['assigned_agent_id'] !== null ? (int) $user['assigned_agent_id'] : 0;
        if ($assignedRaw > 0) {
            $cov = $pdo->prepare(
                'SELECT 1 FROM agent_areas WHERE agent_id = :a AND area_id = :r LIMIT 1'
            );
            $cov->execute(['a' => $agentId, 'r' => $areaId]);
            if ($cov->fetch() === false) {
                throw new RuntimeException(
                    'Your neighborhood is not in your agent’s route; update your service area in profile'
                );
            }
        }

        $stmtPrice = $pdo->prepare(
            'SELECT ap.daily_price, ap.monthly_subscription_price, p.type AS product_type
             FROM agent_products ap
             INNER JOIN products p ON p.id = ap.product_id
             WHERE ap.agent_id = :aid AND ap.product_id = :pid
             LIMIT 1'
        );

        $lines = [];
        $total = '0.00';
        foreach ($items as $i => $row) {
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $qty = isset($row['quantity']) ? (int) $row['quantity'] : 0;
            if ($pid <= 0 || $qty <= 0) {
                throw new RuntimeException('Invalid item at index ' . $i);
            }

            $stmtPrice->execute(['aid' => $agentId, 'pid' => $pid]);
            $priceRow = $stmtPrice->fetch();
            if ($priceRow === false) {
                throw new RuntimeException('Product ' . $pid . ' is not available from your agent');
            }
            $daily = (string) $priceRow['daily_price'];
            $monthly = (string) $priceRow['monthly_subscription_price'];
            $productType = (string) $priceRow['product_type'];

            if ($productType === 'book') {
                $months = isset($row['months']) ? (int) $row['months'] : 0;
                if ($months < 1) {
                    throw new RuntimeException('Magazines require months (>= 1) at item index ' . $i);
                }
                [$startYmd, $endYmd] = $this->magazinePeriodFromMonths($months);
                $lineTotal = bcmul(bcmul($monthly, (string) $months, 4), (string) $qty, 4);
                $lineTotal = bcadd($lineTotal, '0', 2);
                $total = bcadd($total, $lineTotal, 2);
                $lines[] = [
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'start_date' => $startYmd,
                    'end_date' => $endYmd,
                    'unit_daily_price' => $daily,
                    'unit_monthly_price' => $monthly,
                    'line_total' => $lineTotal,
                ];
                continue;
            }

            $start = isset($row['start_date']) ? (string) $row['start_date'] : '';
            $end = isset($row['end_date']) ? (string) $row['end_date'] : '';
            if ($start === '' || $end === '') {
                throw new RuntimeException('Newspapers require start_date and end_date at item index ' . $i);
            }
            try {
                $dStart = new DateTimeImmutable($start);
                $dEnd = new DateTimeImmutable($end);
            } catch (\Exception $e) {
                throw new RuntimeException('Invalid date format in item ' . $i, 0, $e);
            }
            if ($dStart > $dEnd) {
                throw new RuntimeException('start_date must be on or before end_date for item ' . $i);
            }
            $startYmd = $dStart->format('Y-m-d');
            $endYmd = $dEnd->format('Y-m-d');
            $days = $this->inclusiveDays($startYmd, $endYmd);
            $lineTotal = bcmul(
                bcmul($daily, (string) $days, 4),
                (string) $qty,
                4
            );
            $lineTotal = bcadd($lineTotal, '0', 2);
            $total = bcadd($total, $lineTotal, 2);
            $lines[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'start_date' => $startYmd,
                'end_date' => $endYmd,
                'unit_daily_price' => $daily,
                'unit_monthly_price' => $monthly,
                'line_total' => $lineTotal,
            ];
        }

        $mockOrderId = 'mock_ord_' . bin2hex(random_bytes(8));

        $pdo->beginTransaction();
        try {
            $stmtSub = $pdo->prepare(
                'INSERT INTO subscriptions (user_id, agent_id, status, total_amount, payment_gateway_order_id)
                 VALUES (:uid, :aid, :status, :total, :pgid)'
            );
            $stmtSub->execute([
                'uid' => $userId,
                'aid' => $agentId,
                'status' => 'pending_payment',
                'total' => $total,
                'pgid' => $mockOrderId,
            ]);
            $subscriptionId = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                'INSERT INTO subscription_items
                 (subscription_id, product_id, quantity, start_date, end_date, unit_daily_price, unit_monthly_price, line_total)
                 VALUES (:sid, :pid, :qty, :sd, :ed, :udp, :ump, :lt)'
            );
            foreach ($lines as $ln) {
                $stmtItem->execute([
                    'sid' => $subscriptionId,
                    'pid' => $ln['product_id'],
                    'qty' => $ln['quantity'],
                    'sd' => $ln['start_date'],
                    'ed' => $ln['end_date'],
                    'udp' => $ln['unit_daily_price'],
                    'ump' => $ln['unit_monthly_price'],
                    'lt' => $ln['line_total'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'subscription_id' => $subscriptionId,
            'total_amount' => $total,
            'currency' => 'INR',
            'mock_payment_gateway_order_id' => $mockOrderId,
            'items' => $lines,
        ];
    }

    private function resolveAgentIdForArea(PDO $pdo, int $areaId): ?int
    {
        $sql = 'SELECT aa.agent_id
                FROM agent_areas aa
                INNER JOIN agents a ON a.id = aa.agent_id AND a.status = :active
                WHERE aa.area_id = :aid
                ORDER BY aa.agent_id ASC
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $areaId, 'active' => 'active']);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return (int) $row['agent_id'];
    }

    private function inclusiveDays(string $startYmd, string $endYmd): int
    {
        $start = new DateTimeImmutable($startYmd);
        $end = new DateTimeImmutable($endYmd);
        return (int) $start->diff($end)->days + 1;
    }

    /**
     * Billing month alignment: from first day of current calendar month through the last day
     * of the month that is ($months - 1) months later.
     *
     * @return array{0: string, 1: string} [start Y-m-d, end Y-m-d]
     */
    private function magazinePeriodFromMonths(int $months): array
    {
        $start = (new DateTimeImmutable('today'))->modify('first day of this month');
        $end = $start;
        for ($k = 1; $k < $months; $k++) {
            $end = $end->modify('+1 month');
        }
        $end = $end->modify('last day of this month');

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}
