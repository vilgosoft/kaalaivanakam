<?php



declare(strict_types=1);



namespace Paperkaaran\Controllers;



use Paperkaaran\Db\Database;

use Paperkaaran\Utils\JwtHelper;

use Paperkaaran\Utils\Response;



final class EmployeeAuthController

{

    public function login(): void

    {

        $raw = file_get_contents('php://input') ?: '';

        try {

            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        } catch (\JsonException) {

            Response::error('Invalid JSON body', 400);

        }

        $username = isset($body['username']) ? trim((string) $body['username']) : '';

        $password = isset($body['password']) ? (string) $body['password'] : '';

        if ($username === '' || $password === '') {

            Response::error('username and password required', 400);

        }

        $pdo = Database::pdo();

        $stmt = $pdo->prepare(

            'SELECT e.id, e.password_hash, e.status, e.agent_id, a.status AS agent_status

             FROM agent_employees e

             INNER JOIN agents a ON a.id = e.agent_id

             WHERE e.username = :u

             LIMIT 1'

        );

        $stmt->execute(['u' => $username]);

        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {

            Response::error('Invalid credentials', 401);

        }

        if ($row['status'] !== 'active' || $row['agent_status'] !== 'active') {

            Response::error('Account is inactive', 403);

        }

        $id = (int) $row['id'];

        $token = JwtHelper::encode($id, 'employee');

        Response::json([

            'access_token' => $token,

            'token_type' => 'Bearer',

            'employee_id' => $id,

            'agent_id' => (int) $row['agent_id'],

        ]);

    }

}

