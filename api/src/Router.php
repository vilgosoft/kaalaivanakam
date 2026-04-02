<?php

declare(strict_types=1);

namespace Paperkaaran;

use Paperkaaran\Controllers\AgentAreaController;
use Paperkaaran\Controllers\AgentAuthController;
use Paperkaaran\Controllers\AgentController;
use Paperkaaran\Controllers\AgentCustomerController;
use Paperkaaran\Controllers\AgentEmployeeController;
use Paperkaaran\Controllers\AgentProductController;
use Paperkaaran\Controllers\AreaController;
use Paperkaaran\Controllers\EmployeeAuthController;
use Paperkaaran\Controllers\AuthPasswordResetController;
use Paperkaaran\Controllers\UnifiedAuthController;
use Paperkaaran\Controllers\EmployeeDeliveryController;
use Paperkaaran\Controllers\HealthController;
use Paperkaaran\Controllers\HomeController;
use Paperkaaran\Controllers\InvoiceController;
use Paperkaaran\Controllers\ProductController;
use Paperkaaran\Controllers\PublicAreaController;
use Paperkaaran\Controllers\SubscriptionController;
use Paperkaaran\Controllers\SuperAdminAuthController;
use Paperkaaran\Controllers\UserAuthController;
use Paperkaaran\Controllers\UserCatalogController;
use Paperkaaran\Controllers\UserProfileController;
use Paperkaaran\Utils\Response;

final class Router
{
    /**
     * @var list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3: list<string>}>
     */
    private const ROUTES = [
        ['GET', '#^/$#', [HomeController::class, 'index'], []],

        ['GET', '#^/v1/health$#', [HealthController::class, 'index'], []],

        ['POST', '#^/v1/auth/login$#', [UnifiedAuthController::class, 'login'], []],
        ['POST', '#^/v1/auth/forgot-password$#', [AuthPasswordResetController::class, 'forgotPassword'], []],
        ['POST', '#^/v1/auth/reset-password$#', [AuthPasswordResetController::class, 'resetPassword'], []],
        ['POST', '#^/v1/auth/super-admin/login$#', [SuperAdminAuthController::class, 'login'], []],
        ['POST', '#^/v1/auth/agent/login$#', [AgentAuthController::class, 'login'], []],
        ['POST', '#^/v1/auth/employee/login$#', [EmployeeAuthController::class, 'login'], []],
        ['POST', '#^/v1/auth/user/login$#', [UserAuthController::class, 'login'], []],
        ['POST', '#^/v1/auth/user/register$#', [UserAuthController::class, 'register'], []],

        ['GET', '#^/v1/agents$#', [AgentController::class, 'index'], []],
        ['POST', '#^/v1/agents$#', [AgentController::class, 'create'], []],
        ['PATCH', '#^/v1/agents/(\d+)$#', [AgentController::class, 'update'], ['id']],
        ['DELETE', '#^/v1/agents/(\d+)$#', [AgentController::class, 'delete'], ['id']],

        ['GET', '#^/v1/areas$#', [AreaController::class, 'index'], []],
        ['GET', '#^/v1/areas/(\d+)/agent$#', [PublicAreaController::class, 'resolveAgent'], ['id']],

        ['GET', '#^/v1/products$#', [ProductController::class, 'index'], []],

        ['GET', '#^/v1/agent/areas$#', [AgentAreaController::class, 'index'], []],
        ['POST', '#^/v1/agent/areas$#', [AgentAreaController::class, 'save'], []],
        ['POST', '#^/v1/agent/catalog/areas$#', [AgentAreaController::class, 'createCatalog'], []],
        ['DELETE', '#^/v1/agent/catalog/areas/(\d+)$#', [AgentAreaController::class, 'deleteCatalog'], ['id']],
        ['GET', '#^/v1/agent/products$#', [AgentProductController::class, 'index'], []],
        ['POST', '#^/v1/agent/products$#', [AgentProductController::class, 'upsert'], []],
        ['POST', '#^/v1/agent/catalog/products$#', [AgentProductController::class, 'createMaster'], []],
        ['DELETE', '#^/v1/agent/catalog/products/(\d+)$#', [AgentProductController::class, 'deleteMaster'], ['id']],
        ['GET', '#^/v1/agent/customers$#', [AgentCustomerController::class, 'index'], []],
        ['PATCH', '#^/v1/agent/customers/(\d+)$#', [AgentCustomerController::class, 'update'], ['id']],
        ['DELETE', '#^/v1/agent/customers/(\d+)$#', [AgentCustomerController::class, 'destroy'], ['id']],
        ['GET', '#^/v1/agent/employees$#', [AgentEmployeeController::class, 'index'], []],
        ['POST', '#^/v1/agent/employees$#', [AgentEmployeeController::class, 'create'], []],

        ['GET', '#^/v1/employee/deliveries$#', [EmployeeDeliveryController::class, 'index'], []],

        ['GET', '#^/v1/user/profile$#', [UserProfileController::class, 'show'], []],
        ['PATCH', '#^/v1/user/profile$#', [UserProfileController::class, 'update'], []],
        ['GET', '#^/v1/user/catalog$#', [UserCatalogController::class, 'index'], []],
        ['POST', '#^/v1/user/subscriptions/checkout$#', [SubscriptionController::class, 'checkout'], []],
        ['POST', '#^/v1/user/subscriptions/(\d+)/mock-pay$#', [SubscriptionController::class, 'mockPay'], ['id']],
        ['GET', '#^/v1/user/subscriptions$#', [SubscriptionController::class, 'list'], []],
        ['GET', '#^/v1/user/invoices/(\d+)$#', [InvoiceController::class, 'show'], ['id']],
    ];

    public function dispatch(string $method, string $path): void
    {
        foreach (self::ROUTES as $route) {
            [$m, $pattern, $handler, $paramNames] = $route;
            if ($m !== $method) {
                continue;
            }
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }
            $params = [];
            foreach ($paramNames as $i => $name) {
                $params[$name] = $matches[$i + 1] ?? null;
            }
            [$class, $action] = $handler;
            $controller = new $class();
            if ($params === []) {
                $controller->{$action}();
            } else {
                $controller->{$action}($params);
            }
            return;
        }
        Response::error('Not found', 404, 'not_found');
    }
}
