<?php
declare(strict_types=1);

/**
 * Validates the code-project contract independently from model wording.
 * All paths are normalized against the user-selected project root before a
 * member is allowed to start, so UI labels and write enforcement share one
 * authoritative scope.
 */
final class ProjectTeamProtocol
{
    public const CONTRACT_TOOL = 'submit_project_contract';
    public const ACCEPTANCE_TOOL = 'submit_project_acceptance';
    public const REWORK_TOOL = 'request_project_rework';

    public static function contractTool(int $maxMembers = 6): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::CONTRACT_TOOL,
                'description' => '项目负责人提交经过检查的架构、公共契约、文件所有权和项目成员工作包。验证通过后系统才会启动成员。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'string'],
                        'architecture' => ['type' => 'string'],
                        'lead_owned_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'work_packages' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => $maxMembers,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'instruction' => ['type' => 'string'],
                                    'owned_paths' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                                    'read_dependencies' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['id', 'title', 'instruction', 'owned_paths'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['group_id', 'architecture', 'lead_owned_paths', 'acceptance_criteria', 'work_packages'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function acceptanceTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::ACCEPTANCE_TOOL,
                'description' => '项目负责人完成独立集成验证后提交最终验收。completed 必须引用所有成功成员证据且没有未完成项。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'string'],
                        'outcome' => ['type' => 'string', 'enum' => ['completed', 'partial', 'blocked']],
                        'evidence_task_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'checks' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'unresolved' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'item' => ['type' => 'string'],
                                    'reason' => ['type' => 'string'],
                                ],
                                'required' => ['item', 'reason'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['group_id', 'outcome', 'evidence_task_ids', 'checks', 'unresolved'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function reworkTool(int $maxMembers = 6): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::REWORK_TOOL,
                'description' => '验收未通过时，把有证据的具体问题定向交回现有项目成员。不得创建超过既有成员席位的新角色。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'string'],
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => $maxMembers,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'member_id' => ['type' => 'string'],
                                    'instruction' => ['type' => 'string'],
                                    'evidence_task_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['member_id', 'instruction', 'evidence_task_ids'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['group_id', 'items'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function validateContract(
        array $input,
        string $expectedGroupId,
        string $projectRoot,
        int $maxMembers = 6
    ): array {
        $groupId = trim((string)($input['group_id'] ?? ''));
        if ($groupId !== $expectedGroupId) {
            throw new InvalidArgumentException('submit_project_contract 的 group_id 与当前项目不一致');
        }
        $architecture = trim((string)($input['architecture'] ?? ''));
        $criteria = self::stringList($input['acceptance_criteria'] ?? null);
        $packages = $input['work_packages'] ?? null;
        if ($architecture === '' || $criteria === []) {
            throw new InvalidArgumentException('项目合同必须包含架构说明和至少一项验收标准');
        }
        if (!is_array($packages) || $packages === [] || count($packages) > $maxMembers) {
            throw new InvalidArgumentException("项目成员工作包必须为 1-{$maxMembers} 个");
        }
        $leadPaths = self::normalizePathList($input['lead_owned_paths'] ?? null, $projectRoot, true);
        $normalizedPackages = [];
        foreach ($packages as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('项目成员工作包必须是对象');
            }
            $id = trim((string)($row['id'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            $instruction = trim((string)($row['instruction'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $id)) {
                throw new InvalidArgumentException('项目成员工作包 id 必须是 1-80 位安全标识');
            }
            if ($title === '' || $instruction === '') {
                throw new InvalidArgumentException("项目成员工作包 {$id} 缺少职责名称或任务说明");
            }
            if (isset($normalizedPackages[$id])) {
                throw new InvalidArgumentException("项目成员工作包 id 重复：{$id}");
            }
            $owned = self::normalizePathList($row['owned_paths'] ?? null, $projectRoot, false);
            $read = self::normalizePathList($row['read_dependencies'] ?? [], $projectRoot, true);
            $depends = self::stringList($row['depends_on'] ?? []);
            if (in_array($id, $depends, true)) {
                throw new InvalidArgumentException("项目成员 {$id} 不能依赖自身");
            }
            $normalizedPackages[$id] = [
                'id' => $id,
                'title' => $title,
                'instruction' => $instruction,
                'owned_paths' => $owned,
                'read_dependencies' => $read,
                'depends_on' => $depends,
            ];
        }

        foreach ($normalizedPackages as $package) {
            foreach ($package['depends_on'] as $dependency) {
                if (!isset($normalizedPackages[$dependency])) {
                    throw new InvalidArgumentException("项目成员依赖不存在：{$dependency}");
                }
            }
        }
        self::assertAcyclic($normalizedPackages);

        $owners = [];
        foreach ($leadPaths as $path) {
            $owners[] = ['owner' => 'lead', 'path' => $path];
        }
        foreach ($normalizedPackages as $package) {
            foreach ($package['owned_paths'] as $path) {
                foreach ($owners as $existing) {
                    if (self::pathsOverlap($path, $existing['path'])) {
                        throw new InvalidArgumentException(
                            "并行写入范围重叠：{$package['id']} 的 {$path} 与 {$existing['owner']} 的 {$existing['path']}"
                        );
                    }
                }
                $owners[] = ['owner' => $package['id'], 'path' => $path];
            }
        }

        return [
            'group_id' => $groupId,
            'architecture' => $architecture,
            'lead_owned_paths' => $leadPaths,
            'acceptance_criteria' => $criteria,
            'work_packages' => array_values($normalizedPackages),
        ];
    }

    public static function validateAcceptance(
        array $input,
        string $expectedGroupId,
        array $memberResults
    ): array {
        if (trim((string)($input['group_id'] ?? '')) !== $expectedGroupId) {
            throw new InvalidArgumentException('submit_project_acceptance 的 group_id 与当前项目不一致');
        }
        $outcome = strtolower(trim((string)($input['outcome'] ?? '')));
        if (!in_array($outcome, ['completed', 'partial', 'blocked'], true)) {
            throw new InvalidArgumentException('项目验收 outcome 无效');
        }
        $evidence = self::stringList($input['evidence_task_ids'] ?? null);
        $checks = self::stringList($input['checks'] ?? null);
        $unresolved = is_array($input['unresolved'] ?? null) ? array_values($input['unresolved']) : [];
        foreach ($unresolved as $row) {
            if (!is_array($row) || trim((string)($row['item'] ?? '')) === '' || trim((string)($row['reason'] ?? '')) === '') {
                throw new InvalidArgumentException('项目验收 unresolved 必须包含 item 和 reason');
            }
        }
        $known = array_fill_keys(array_keys($memberResults), true);
        foreach ($evidence as $taskId) {
            if (!isset($known[$taskId])) {
                throw new InvalidArgumentException("项目验收引用了未知成员证据：{$taskId}");
            }
        }
        $successful = array_keys(array_filter(
            $memberResults,
            static fn(array $result): bool => ($result['status'] ?? '') === 'success'
        ));
        if ($outcome === 'completed') {
            $missing = array_values(array_diff(array_keys($memberResults), $successful));
            if ($missing !== [] || array_diff($successful, $evidence) !== [] || $unresolved !== [] || $checks === []) {
                throw new InvalidArgumentException('completed 验收必须引用全部成功成员、包含实际检查且没有未完成项');
            }
        } elseif ($outcome === 'partial') {
            if ($evidence === [] || $unresolved === []) {
                throw new InvalidArgumentException('partial 验收必须同时包含成功证据和未完成项');
            }
        } elseif ($unresolved === []) {
            throw new InvalidArgumentException('blocked 验收必须说明阻塞项');
        }
        return [
            'group_id' => $expectedGroupId,
            'outcome' => $outcome,
            'evidence_task_ids' => $evidence,
            'checks' => $checks,
            'unresolved' => $unresolved,
        ];
    }

    public static function eventContext(array $actor, string $phase): array
    {
        return [
            'project_group_id' => (string)($actor['project_group_id'] ?? ''),
            'actor_id' => (string)($actor['actor_id'] ?? ''),
            'role_key' => (string)($actor['role_key'] ?? ''),
            'role_label' => (string)($actor['role_label'] ?? ''),
            'workstream' => (string)($actor['workstream'] ?? ''),
            'owned_paths' => array_values($actor['owned_paths'] ?? []),
            'depends_on' => array_values($actor['depends_on'] ?? []),
            'project_phase' => $phase,
        ];
    }

    private static function normalizePathList($input, string $root, bool $allowEmpty): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('项目文件范围必须是数组');
        }
        $paths = [];
        foreach ($input as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $paths[] = self::normalizeProjectPath($path, $root);
        }
        $paths = array_values(array_unique($paths));
        if (!$allowEmpty && $paths === []) {
            throw new InvalidArgumentException('项目成员必须拥有至少一个独占写入路径');
        }
        return $paths;
    }

    public static function normalizeProjectPath(string $path, string $root): string
    {
        $root = self::lexicalPath($root);
        if ($root === '') {
            throw new InvalidArgumentException('代码项目缺少项目根目录');
        }
        $candidate = trim(str_replace('/', '\\', $path));
        if (!preg_match('/^[A-Za-z]:\\\\/', $candidate) && !str_starts_with($candidate, '\\\\')) {
            $candidate = rtrim($root, '\\') . '\\' . ltrim($candidate, '\\');
        }
        $candidate = self::lexicalPath($candidate);
        $rootCompare = mb_strtolower(rtrim($root, '\\'), 'UTF-8');
        $candidateCompare = mb_strtolower($candidate, 'UTF-8');
        if ($candidateCompare !== $rootCompare && !str_starts_with($candidateCompare, $rootCompare . '\\')) {
            throw new InvalidArgumentException("项目文件范围越过项目根目录：{$path}");
        }
        return $candidate;
    }

    public static function pathWithinScopes(string $path, array $scopes, string $root): bool
    {
        try {
            $candidate = mb_strtolower(self::normalizeProjectPath($path, $root), 'UTF-8');
        } catch (Throwable $e) {
            return false;
        }
        foreach ($scopes as $scope) {
            $normalized = mb_strtolower(rtrim((string)$scope, '\\'), 'UTF-8');
            if ($candidate === $normalized || str_starts_with($candidate, $normalized . '\\')) {
                return true;
            }
        }
        return false;
    }

    private static function lexicalPath(string $path): string
    {
        $path = preg_replace('/\\\\+/', '\\', trim(str_replace('/', '\\', $path))) ?: '';
        $prefix = '';
        if (preg_match('/^[A-Za-z]:/', $path, $match)) {
            $prefix = strtoupper($path[0]) . ':';
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '\\\\')) {
            $prefix = '\\\\';
            $path = ltrim($path, '\\');
        }
        $parts = [];
        foreach (explode('\\', trim($path, '\\')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new InvalidArgumentException('项目路径不能越过根目录');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return $prefix . ($prefix !== '\\\\' ? '\\' : '') . implode('\\', $parts);
    }

    private static function pathsOverlap(string $left, string $right): bool
    {
        $left = mb_strtolower(rtrim($left, '\\'), 'UTF-8');
        $right = mb_strtolower(rtrim($right, '\\'), 'UTF-8');
        return $left === $right
            || str_starts_with($left, $right . '\\')
            || str_starts_with($right, $left . '\\');
    }

    private static function stringList($input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('协议字段必须是数组');
        }
        return array_values(array_unique(array_filter(
            array_map(static fn($value): string => trim((string)$value), $input),
            static fn(string $value): bool => $value !== ''
        )));
    }

    private static function assertAcyclic(array $packages): void
    {
        $resolved = [];
        while (count($resolved) < count($packages)) {
            $progress = false;
            foreach ($packages as $id => $package) {
                if (isset($resolved[$id])) {
                    continue;
                }
                $waiting = array_filter(
                    $package['depends_on'],
                    static fn(string $dependency): bool => !isset($resolved[$dependency])
                );
                if ($waiting === []) {
                    $resolved[$id] = true;
                    $progress = true;
                }
            }
            if (!$progress) {
                throw new InvalidArgumentException('项目成员工作包依赖形成循环');
            }
        }
    }
}
