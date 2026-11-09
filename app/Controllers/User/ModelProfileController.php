<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\ModelProfileRepository;
use App\Services\ModelProfileService;
use App\Support\Tenant;

class ModelProfileController
{
    public function __construct(
        private ModelProfileRepository $repository,
        private ModelProfileService $service,
    ) {}

    public function index(Request $request): Response
    {
        $scope = Tenant::scope();
        return Response::json(['ok' => true, 'data' => [
            'items' => array_map([ModelProfileRepository::class, 'publicProfile'], $this->repository->list($scope)),
            'count' => $this->repository->countForUser($scope),
            'limit' => ModelProfileRepository::MAX_PER_USER,
        ]]);
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $scope = Tenant::scope();
        if ($name === '') {
            return $this->fail('档案名不能为空');
        }
        if ($this->repository->nameExists($name, $scope)) {
            return $this->fail('同名档案已存在');
        }
        if ($this->repository->countForUser($scope) >= ModelProfileRepository::MAX_PER_USER) {
            return $this->fail('档案数量已达上限');
        }
        $data = $this->extract($payload);
        $data['name'] = $name;
        $data['user_id'] = Tenant::writeId();
        $id = $this->repository->create($data);
        return $this->profileResponse($id, $scope);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $profile = $this->repository->findForUser($id, $scope);
        if ($profile === null) {
            return $this->fail('档案不存在', 404);
        }
        $payload = $request->json();
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->fail('档案名不能为空');
            }
            if ($this->repository->nameExists($name, $scope, $id)) {
                return $this->fail('同名档案已存在');
            }
            $payload['name'] = $name;
        }
        $data = $this->extract($payload, $profile);
        if ($data !== []) {
            $this->repository->update($id, $data, Tenant::scope());
        }
        return $this->profileResponse($id, $scope);
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('档案不存在', 404);
        }
        $this->repository->delete($id, Tenant::scope());
        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    public function test(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $profile = $this->repository->findForUser($id, Tenant::scope());
        if ($profile === null) {
            return $this->fail('档案不存在', 404);
        }
        $result = $this->service->testConnection($profile);
        return Response::json(['ok' => $result['ok'], 'data' => [
            'status' => $result['status'],
            'message' => $result['message'],
            'latency_ms' => $result['latency_ms'],
            'models' => $result['models'] ?? [],
            'profile' => ModelProfileRepository::publicProfile($this->repository->findForUser($id, Tenant::scope()) ?? []),
        ]], $result['ok'] ? 200 : 502);
    }

    public function setDefault(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        if ($this->repository->findForUser($id, $scope) === null) {
            return $this->fail('档案不存在', 404);
        }
        $this->repository->markDefault($id, $scope);
        return $this->profileResponse($id, $scope);
    }

    private function profileResponse(int $id, ?int $scope): Response
    {
        return Response::json(['ok' => true, 'data' => [
            'profile' => ModelProfileRepository::publicProfile($this->repository->findForUser($id, $scope) ?? []),
        ]]);
    }

    private function extract(array $payload, ?array $profile = null): array
    {
        $data = [];
        foreach (['name', 'provider_type', 'api_endpoint', 'model_name', 'api_key'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        if ($profile !== null && isset($data['api_key']) && ($data['api_key'] === '' || $data['api_key'] === '********')) {
            unset($data['api_key']);
        }
        if (($payload['clear_api_key'] ?? false) === true) {
            $data['api_key'] = '';
        }
        foreach (['custom_headers', 'custom_params'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $data[$key] = $payload[$key];
            }
        }
        if (isset($data['custom_headers'])) {
            foreach ($data['custom_headers'] as $name => $value) {
                if ($value !== '********') {
                    continue;
                }
                unset($data['custom_headers'][$name]);
                if (!preg_match('/authorization|api[-_]?key|token|secret|cookie|credential/i', (string) $name)) {
                    continue;
                }
                foreach ((array) ($profile['custom_headers'] ?? []) as $existingName => $existingValue) {
                    if (strcasecmp((string) $name, (string) $existingName) === 0) {
                        $data['custom_headers'][$existingName] = $existingValue;
                        break;
                    }
                }
            }
        }
        foreach (['enable_tool_call', 'enable_vision', 'is_default', 'enabled'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = !empty($payload[$key]);
            }
        }
        return $data;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
