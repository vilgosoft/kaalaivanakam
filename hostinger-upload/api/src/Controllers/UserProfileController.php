<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Utils\Response;

final class UserProfileController
{
    public function show(): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, name, phone, email_id, address, latitude, longitude, area_id, assigned_agent_id, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Response::error('User not found', 404);
        }
        Response::json(['user' => $row]);
    }

    public function update(): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $name = isset($body['name']) ? (string) $body['name'] : null;
        $email = isset($body['email_id']) ? (string) $body['email_id'] : null;
        $address = isset($body['address']) ? (string) $body['address'] : null;
        $lat = array_key_exists('latitude', $body) ? $body['latitude'] : null;
        $lng = array_key_exists('longitude', $body) ? $body['longitude'] : null;
        $areaId = array_key_exists('area_id', $body) ? $body['area_id'] : null;

        $pdo = Database::pdo();
        $fields = [];
        $params = ['id' => $userId];
        if ($name !== null) {
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($email !== null) {
            $fields[] = 'email_id = :email';
            $params['email'] = $email;
        }
        if ($address !== null) {
            $fields[] = 'address = :addr';
            $params['addr'] = $address;
        }
        if ($lat !== null) {
            $fields[] = 'latitude = :lat';
            $params['lat'] = $lat === '' || $lat === null ? null : $lat;
        }
        if ($lng !== null) {
            $fields[] = 'longitude = :lng';
            $params['lng'] = $lng === '' || $lng === null ? null : $lng;
        }
        if ($areaId !== null) {
            $aid = (int) $areaId;
            if ($aid > 0) {
                $chk = $pdo->prepare('SELECT id FROM areas WHERE id = :id LIMIT 1');
                $chk->execute(['id' => $aid]);
                if ($chk->fetch() === false) {
                    Response::error('Invalid area_id', 400);
                }
                $uStmt = $pdo->prepare('SELECT assigned_agent_id FROM users WHERE id = :id LIMIT 1');
                $uStmt->execute(['id' => $userId]);
                $uRow = $uStmt->fetch();
                $assigned = $uRow !== false && $uRow['assigned_agent_id'] !== null
                    ? (int) $uRow['assigned_agent_id']
                    : 0;
                if ($assigned > 0) {
                    $cov = $pdo->prepare(
                        'SELECT 1 FROM agent_areas WHERE agent_id = :a AND area_id = :r LIMIT 1'
                    );
                    $cov->execute(['a' => $assigned, 'r' => $aid]);
                    if ($cov->fetch() === false) {
                        Response::error('Choose a neighborhood your agent serves', 403);
                    }
                }
            }
            $fields[] = 'area_id = :area_id';
            $params['area_id'] = $aid > 0 ? $aid : null;
        }
        if ($fields === []) {
            Response::error('No fields to update', 400);
        }
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        $stmt = $pdo->prepare('SELECT id, name, phone, email_id, address, latitude, longitude, area_id, assigned_agent_id, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        Response::json(['user' => $stmt->fetch()]);
    }
}
