<?php
declare(strict_types=1);

function officeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$backendRoot = dirname(__DIR__);
$workspaceRoot = dirname($backendRoot);
$requirements = (string)file_get_contents($workspaceRoot . '/MoonYa-Python/requirements.txt');
$pythonService = (string)file_get_contents(
    $workspaceRoot . '/MoonYa-Win/MoonYa-Solution/MoonYa/Services/PythonExecutionService.cs'
);
$executionServer = (string)file_get_contents(
    $workspaceRoot . '/MoonYa-Win/MoonYa-Solution/MoonYa/Services/ExecutionApiServer.cs'
);
$schema = (string)file_get_contents($backendRoot . '/Services/MultiAgentSchema.php');
$gateway = (string)file_get_contents($backendRoot . '/Services/ToolGateway.php');
$coordinator = (string)file_get_contents($backendRoot . '/Services/TeamCoordinator.php');
$smoke = (string)file_get_contents(__DIR__ . '/office_runtime_smoke_test.py');

$versions = [
    'python-docx' => '1.2.0',
    'openpyxl' => '3.1.5',
    'python-pptx' => '1.0.2',
    'pypdf' => '6.14.2',
];
foreach ($versions as $distribution => $version) {
    officeAssert(
        str_contains($requirements, "{$distribution}=={$version}")
            && str_contains($pythonService, "(\"{$distribution}\", \"{$version}\")"),
        "Pinned Office dependency drifted: {$distribution}"
    );
}

$missingCheck = strpos($pythonService, 'ErrorCode = "missing_runtime_dependency"');
$sandboxExecution = strpos($pythonService, 'return await ExecuteInSandboxAsync');
officeAssert(
    $missingCheck !== false
        && $sandboxExecution !== false
        && $missingCheck < $sandboxExecution
        && str_contains($pythonService, 'Agent 不得临时执行 pip install'),
    'Office dependency failure is not enforced before Python execution'
);
officeAssert(
    str_contains($executionServer, 'office_ready = _pythonService.OfficeDependenciesReady')
        && str_contains($executionServer, 'office_dependencies = _pythonService.OfficeDependencyStatus'),
    'Execution service health response no longer reports Office readiness'
);
officeAssert(
    str_contains($schema, 'artifact_path 和 verification')
        && str_contains($gateway, 'managed_runtime_install_forbidden')
        && str_contains($gateway, "agentOwnsRoutingCapability(\$agentKey, 'file.office')")
        && str_contains($coordinator, 'office_artifact_required'),
    'File Agent Office runtime or artifact guard regressed'
);
foreach ([
    'Document(docx_path)',
    'load_workbook(xlsx_path',
    'Presentation(pptx_path)',
    'PdfReader(pdf_path)',
] as $reopenContract) {
    officeAssert(
        str_contains($smoke, $reopenContract),
        "Office smoke test no longer reopens artifact: {$reopenContract}"
    );
}

echo "office runtime contract: PASS\n";
