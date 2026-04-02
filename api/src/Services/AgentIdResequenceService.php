<?php

declare(strict_types=1);

namespace Paperkaaran\Services;

use PDO;

/**
 * After an agent row is removed, compacts remaining agents to ids 1..n (by current sort order)
 * and rewrites all FKs. Uses a high temporary id range to avoid PK clashes.
 *
 * Note: Any JWT still holding an old agent id becomes invalid; agents should sign in again.
 */
final class AgentIdResequenceService
{
    private const TEMP_BASE = 2100000000;

    public static function resequence(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT id FROM agents ORDER BY id ASC');
        /** @var list<string|int|false> $col */
        $col = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map(static fn ($v) => (int) $v, $col);

        if ($ids === []) {
            $pdo->exec('ALTER TABLE agents AUTO_INCREMENT = 1');

            return;
        }

        $n = count($ids);
        $sequential = true;
        foreach ($ids as $i => $v) {
            if ($v !== $i + 1) {
                $sequential = false;
                break;
            }
        }
        if ($sequential) {
            $pdo->exec('ALTER TABLE agents AUTO_INCREMENT = ' . ($n + 1));

            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($ids as $old) {
                $tmp = self::TEMP_BASE + $old;
                self::rewriteAgentFk($pdo, $old, $tmp);
                $pdo->prepare('UPDATE agents SET id = :t WHERE id = :o')->execute(['t' => $tmp, 'o' => $old]);
            }

            $stmt2 = $pdo->query('SELECT id FROM agents ORDER BY id ASC');
            /** @var list<string|int|false> $col2 */
            $col2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            $temps = array_map(static fn ($v) => (int) $v, $col2);

            foreach ($temps as $i => $tmp) {
                $new = $i + 1;
                if ($tmp === $new) {
                    continue;
                }
                self::rewriteAgentFk($pdo, $tmp, $new);
                $pdo->prepare('UPDATE agents SET id = :n WHERE id = :t')->execute(['n' => $new, 't' => $tmp]);
            }

            $pdo->exec('ALTER TABLE agents AUTO_INCREMENT = ' . ($n + 1));
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function rewriteAgentFk(PDO $pdo, int $from, int $to): void
    {
        $p = static function (string $sql) use ($pdo, $from, $to): void {
            $st = $pdo->prepare($sql);
            $st->execute(['t' => $to, 'f' => $from]);
        };

        $p('UPDATE agent_areas SET agent_id = :t WHERE agent_id = :f');
        $p('UPDATE agent_products SET agent_id = :t WHERE agent_id = :f');
        $p('UPDATE agent_employees SET agent_id = :t WHERE agent_id = :f');
        $p('UPDATE users SET assigned_agent_id = :t WHERE assigned_agent_id = :f');
        $p('UPDATE subscriptions SET agent_id = :t WHERE agent_id = :f');
    }
}
