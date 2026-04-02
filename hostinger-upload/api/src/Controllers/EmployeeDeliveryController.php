<?php



declare(strict_types=1);



namespace Paperkaaran\Controllers;



use Paperkaaran\Db\Database;

use Paperkaaran\Middleware\AuthMiddleware;

use Paperkaaran\Utils\Response;



final class EmployeeDeliveryController

{

    /** Active subscriptions only — customers who need ongoing delivery. */

    public function index(): void

    {

        $auth = AuthMiddleware::requireBearer('employee');

        $employeeId = (int) $auth['sub'];

        $pdo = Database::pdo();

        $stmt = $pdo->prepare(

            'SELECT id, agent_id FROM agent_employees WHERE id = :id AND status = :st LIMIT 1'

        );

        $stmt->execute(['id' => $employeeId, 'st' => 'active']);

        $emp = $stmt->fetch();

        if ($emp === false) {

            Response::error('Employee not found', 404);

        }

        $agentId = (int) $emp['agent_id'];



        $custStmt = $pdo->prepare(

            'SELECT DISTINCT u.id, u.name, u.phone, u.email_id, u.address, u.latitude, u.longitude, ar.name AS area_name

             FROM users u

             INNER JOIN subscriptions s ON s.user_id = u.id AND s.agent_id = :aid AND s.status = :active

             LEFT JOIN areas ar ON ar.id = u.area_id

             ORDER BY u.name, u.id'

        );

        $custStmt->execute(['aid' => $agentId, 'active' => 'active']);

        /** @var list<array<string, mixed>> $customers */

        $customers = $custStmt->fetchAll();



        $subStmt = $pdo->prepare(

            'SELECT s.id, s.user_id, s.status, s.total_amount, s.created_at, s.payment_gateway_order_id, i.invoice_number

             FROM subscriptions s

             LEFT JOIN invoices i ON i.subscription_id = s.id

             WHERE s.agent_id = :aid AND s.status = :active

             ORDER BY s.id DESC'

        );

        $subStmt->execute(['aid' => $agentId, 'active' => 'active']);

        /** @var list<array<string, mixed>> $subs */

        $subs = $subStmt->fetchAll();



        $linesBySub = [];

        if ($subs !== []) {

            $subIds = array_map(static fn (array $s): int => (int) $s['id'], $subs);

            $placeholders = implode(',', array_fill(0, count($subIds), '?'));

            $lineStmt = $pdo->prepare(

                "SELECT si.subscription_id, si.quantity, si.start_date, si.end_date, si.line_total, p.title AS product_title

                 FROM subscription_items si

                 INNER JOIN products p ON p.id = si.product_id

                 WHERE si.subscription_id IN ($placeholders)

                 ORDER BY si.id ASC"

            );

            $lineStmt->execute($subIds);

            foreach ($lineStmt->fetchAll() as $ln) {

                $sid = (int) $ln['subscription_id'];

                if (!isset($linesBySub[$sid])) {

                    $linesBySub[$sid] = [];

                }

                $linesBySub[$sid][] = [

                    'product_title' => $ln['product_title'],

                    'quantity' => (int) $ln['quantity'],

                    'start_date' => $ln['start_date'],

                    'end_date' => $ln['end_date'],

                    'line_total' => $ln['line_total'],

                ];

            }

        }



        $subsByUser = [];

        foreach ($subs as $s) {

            $uid = (int) $s['user_id'];

            $sid = (int) $s['id'];

            if (!isset($subsByUser[$uid])) {

                $subsByUser[$uid] = [];

            }

            $subsByUser[$uid][] = [

                'id' => $sid,

                'status' => $s['status'],

                'total_amount' => $s['total_amount'],

                'created_at' => $s['created_at'],

                'payment_gateway_order_id' => $s['payment_gateway_order_id'],

                'invoice_number' => $s['invoice_number'],

                'lines' => $linesBySub[$sid] ?? [],

            ];

        }



        $stops = [];

        foreach ($customers as $c) {

            $uid = (int) $c['id'];

            $lat = $c['latitude'];

            $lng = $c['longitude'];

            $mapsUrl = null;

            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {

                $la = (string) $lat;

                $lo = (string) $lng;

                $mapsUrl = 'https://www.google.com/maps?q=' . rawurlencode($la . ',' . $lo);

            }

            $stops[] = [

                'user' => [

                    'id' => $uid,

                    'name' => $c['name'],

                    'phone' => $c['phone'],

                    'email_id' => $c['email_id'],

                    'address' => $c['address'],

                    'area_name' => $c['area_name'],

                    'latitude' => $lat,

                    'longitude' => $lng,

                    'maps_url' => $mapsUrl,

                ],

                'subscriptions' => $subsByUser[$uid] ?? [],

            ];

        }



        Response::json(['stops' => $stops]);

    }

}

