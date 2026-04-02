<?php



declare(strict_types=1);



namespace Paperkaaran\Controllers;



use Paperkaaran\Db\Database;

use Paperkaaran\Middleware\AuthMiddleware;

use Paperkaaran\Utils\Response;



final class InvoiceController

{

    public function show(array $params): void

    {

        $auth = AuthMiddleware::requireBearer('user');

        $userId = (int) $auth['sub'];

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {

            Response::error('Invalid invoice id', 400);

        }

        $pdo = Database::pdo();

        $sql = 'SELECT i.id, i.subscription_id, i.invoice_number, i.amount, i.issued_at, i.pdf_path, i.snapshot_json

                FROM invoices i

                INNER JOIN subscriptions s ON s.id = i.subscription_id

                WHERE i.id = :iid AND s.user_id = :uid

                LIMIT 1';

        $stmt = $pdo->prepare($sql);

        $stmt->execute(['iid' => $id, 'uid' => $userId]);

        $row = $stmt->fetch();

        if ($row === false) {

            Response::error('Invoice not found', 404);

        }



        $invoice = $this->normalizeInvoiceRow($row);

        $sid = (int) $row['subscription_id'];



        $stmtCtx = $pdo->prepare(

            'SELECT s.id, s.status, s.total_amount, s.payment_gateway_order_id, s.created_at,

                    a.id AS agent_id, a.name AS agent_name, a.phone_number AS agent_phone,

                    a.email_id AS agent_email, a.address AS agent_address,

                    u.name AS customer_name, u.email_id AS customer_email, u.phone AS customer_phone,

                    u.address AS customer_address,

                    ar.name AS area_name

             FROM subscriptions s

             INNER JOIN agents a ON a.id = s.agent_id

             INNER JOIN users u ON u.id = s.user_id

             LEFT JOIN areas ar ON ar.id = u.area_id

             WHERE s.id = :sid AND s.user_id = :uid

             LIMIT 1'

        );

        $stmtCtx->execute(['sid' => $sid, 'uid' => $userId]);

        $ctx = $stmtCtx->fetch();

        if ($ctx === false) {

            Response::error('Subscription not found', 404);

        }



        $stmtLines = $pdo->prepare(

            'SELECT si.id, si.product_id, si.quantity, si.start_date, si.end_date,

                    si.unit_daily_price, si.unit_monthly_price, si.line_total,

                    p.title AS product_title, p.type AS product_type

             FROM subscription_items si

             INNER JOIN products p ON p.id = si.product_id

             WHERE si.subscription_id = :sid

             ORDER BY si.id ASC'

        );

        $stmtLines->execute(['sid' => $sid]);

        $lines = $stmtLines->fetchAll();



        $lineOut = [];

        foreach ($lines as $ln) {

            $lineOut[] = [

                'id' => (int) $ln['id'],

                'product_id' => (int) $ln['product_id'],

                'product_title' => $ln['product_title'],

                'product_type' => $ln['product_type'],

                'quantity' => (int) $ln['quantity'],

                'start_date' => $ln['start_date'],

                'end_date' => $ln['end_date'],

                'unit_daily_price' => $ln['unit_daily_price'],

                'unit_monthly_price' => $ln['unit_monthly_price'],

                'line_total' => $ln['line_total'],

            ];

        }



        Response::json([

            'invoice' => $invoice,

            'subscription' => [

                'id' => (int) $ctx['id'],

                'status' => $ctx['status'],

                'total_amount' => $ctx['total_amount'],

                'payment_gateway_order_id' => $ctx['payment_gateway_order_id'],

                'created_at' => $ctx['created_at'],

            ],

            'customer' => [

                'name' => $ctx['customer_name'],

                'email_id' => $ctx['customer_email'],

                'phone' => $ctx['customer_phone'],

                'address' => $ctx['customer_address'],

                'area_name' => $ctx['area_name'],

            ],

            'billing' => [

                'agent_id' => (int) $ctx['agent_id'],

                'name' => $ctx['agent_name'],

                'phone' => $ctx['agent_phone'],

                'email_id' => $ctx['agent_email'],

                'address' => $ctx['agent_address'],

            ],

            'lines' => $lineOut,

        ]);

    }



    /**

     * @param array<string, mixed> $row

     * @return array<string, mixed>

     */

    private function normalizeInvoiceRow(array $row): array

    {

        $out = [

            'id' => (int) $row['id'],

            'subscription_id' => (int) $row['subscription_id'],

            'invoice_number' => $row['invoice_number'],

            'amount' => $row['amount'],

            'issued_at' => $row['issued_at'],

            'pdf_path' => $row['pdf_path'],

            'snapshot_json' => null,

        ];

        $snap = $row['snapshot_json'] ?? null;

        if ($snap === null || $snap === '') {

            return $out;

        }

        if (is_string($snap)) {

            $decoded = json_decode($snap, true);

            $out['snapshot_json'] = is_array($decoded) ? $decoded : $snap;

        } elseif (is_array($snap)) {

            $out['snapshot_json'] = $snap;

        }



        return $out;

    }

}

