using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using MoonYa.Services;

static JsonElement Parse(string json)
{
    using var document = JsonDocument.Parse(json);
    return document.RootElement.Clone();
}

static void AssertOk(JsonElement result, string operation)
{
    if (!result.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True)
    {
        throw new InvalidOperationException($"{operation} failed: {result}");
    }
}

static int FreePort()
{
    var listener = new TcpListener(IPAddress.Loopback, 0);
    listener.Start();
    var port = ((IPEndPoint)listener.LocalEndpoint).Port;
    listener.Stop();
    return port;
}

if (args.Length != 3)
{
    Console.Error.WriteLine("Usage: MoonYa.McpSmoke <python> <fake_mcp_stdio.py> <fake_mcp_http.py>");
    return 2;
}

var python = Path.GetFullPath(args[0]);
var stdioScript = Path.GetFullPath(args[1]);
var httpScript = Path.GetFullPath(args[2]);
var port = FreePort();
using var httpServer = Process.Start(new ProcessStartInfo
{
    FileName = python,
    ArgumentList = { httpScript, port.ToString() },
    UseShellExecute = false,
    RedirectStandardOutput = true,
    RedirectStandardError = true,
    CreateNoWindow = true,
}) ?? throw new InvalidOperationException("Cannot start fake Streamable HTTP MCP server");

try
{
    using var readiness = new HttpClient { Timeout = TimeSpan.FromMilliseconds(200) };
    var ready = false;
    for (var attempt = 0; attempt < 80; attempt++)
    {
        try
        {
            using var response = await readiness.GetAsync($"http://127.0.0.1:{port}/health");
            if (response.IsSuccessStatusCode)
            {
                ready = true;
                break;
            }
        }
        catch
        {
            await Task.Delay(25);
        }
    }
    if (!ready) throw new InvalidOperationException("Fake Streamable HTTP MCP server did not start");

    await using var manager = new McpClientManager();
    var toolsChanged = false;
    manager.ToolsChanged += (_, _) => toolsChanged = true;
    if (!McpClientManager.ValidateOAuthCallback("expected-state", "expected-state", "auth-code") ||
        McpClientManager.ValidateOAuthCallback("expected-state", "wrong-state", "auth-code") ||
        McpClientManager.ValidateOAuthCallback("expected-state", "expected-state", null))
    {
        throw new InvalidOperationException("OAuth state/code validation failed");
    }
    const string testSecret = "mcp-smoke-secret-never-log";
    var secretResultText = await manager.ExecuteAsync(JsonSerializer.Serialize(new
    {
        action = "store_secret",
        user_id = 910001,
        server_key = "stdio-smoke",
        secret_key = "smoke",
        secret = testSecret
    }));
    if (secretResultText.Contains(testSecret, StringComparison.Ordinal))
        throw new InvalidOperationException("Sensitive MCP value leaked into the bridge response");
    var secretResult = Parse(secretResultText);
    AssertOk(secretResult, "DPAPI secret store");
    var vaultReference = secretResult.GetProperty("vault_ref").GetString()!;
    var vaultKey = vaultReference["vault://".Length..];
    var vault = new SecureTokenVault();
    if (await vault.GetJsonAsync<string>(vaultKey) != testSecret)
        throw new InvalidOperationException("DPAPI vault did not round-trip the secret for the current Windows user");
    await vault.DeleteAsync(vaultKey);

    var configure = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
    {
        action = "configure",
        servers = new object[]
        {
            new
            {
                server_key = "stdio-smoke",
                display_name = "stdio smoke",
                transport = "stdio",
                command_path = python,
                arguments_json = new[] { stdioScript },
                environment_json = new { },
                oauth_config_json = new { timeout_seconds = 10 },
                auth_mode = "none"
            },
            new
            {
                server_key = "http-smoke",
                display_name = "http smoke",
                transport = "streamable_http",
                endpoint = $"http://127.0.0.1:{port}/mcp",
                arguments_json = Array.Empty<string>(),
                environment_json = new { },
                oauth_config_json = new { timeout_seconds = 10 },
                auth_mode = "none"
            }
        }
    })));
    AssertOk(configure, "configure");

    foreach (var serverKey in new[] { "stdio-smoke", "http-smoke" })
    {
        var list = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
        {
            action = "list_tools",
            request_id = $"list-{serverKey}",
            user_id = 910001,
            server_key = serverKey,
            timeout_seconds = 10
        })));
        AssertOk(list, $"{serverKey} list");
        if (list.GetProperty("tools").GetArrayLength() != 3)
            throw new InvalidOperationException($"{serverKey} pagination did not return all tools");

        var call = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
        {
            action = "call_tool",
            request_id = $"call-{serverKey}",
            user_id = 910001,
            server_key = serverKey,
            tool_name = "echo",
            arguments = new { value = serverKey },
            timeout_seconds = 10
        })));
        AssertOk(call, $"{serverKey} call");
        if (!call.GetProperty("content").GetString()!.Contains(serverKey, StringComparison.Ordinal))
            throw new InvalidOperationException($"{serverKey} call result was not normalized");

        var artifact = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
        {
            action = "call_tool",
            request_id = $"artifact-{serverKey}",
            user_id = 910001,
            server_key = serverKey,
            tool_name = "artifact",
            arguments = new { },
            timeout_seconds = 10
        })));
        AssertOk(artifact, $"{serverKey} artifact");
        if (artifact.GetProperty("artifacts").GetArrayLength() != 1)
            throw new InvalidOperationException($"{serverKey} resource artifact was not extracted");

        var health = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
        {
            action = "health",
            request_id = $"health-{serverKey}",
            user_id = 910001,
            server_key = serverKey,
            timeout_seconds = 10
        })));
        AssertOk(health, $"{serverKey} health");
    }

    var cancelled = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
    {
        action = "call_tool",
        request_id = "slow-http",
        user_id = 910001,
        server_key = "http-smoke",
        tool_name = "slow",
        arguments = new { },
        timeout_seconds = 1
    })));
    if (cancelled.GetProperty("ok").GetBoolean() ||
        cancelled.GetProperty("error").GetProperty("code").GetString() != "cancelled")
    {
        throw new InvalidOperationException("HTTP timeout/cancellation was not normalized");
    }

    var disconnect = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
    {
        action = "disconnect",
        user_id = 910001,
        server_key = "http-smoke"
    })));
    AssertOk(disconnect, "disconnect");
    var reconnect = Parse(await manager.ExecuteAsync(JsonSerializer.Serialize(new
    {
        action = "health",
        user_id = 910001,
        server_key = "http-smoke",
        timeout_seconds = 10
    })));
    AssertOk(reconnect, "reconnect");

    if (!toolsChanged)
        throw new InvalidOperationException("tools/list_changed notification was not received from stdio");

    Console.WriteLine("MCP stdio + Streamable HTTP smoke: PASS");
    return 0;
}
finally
{
    if (!httpServer.HasExited)
    {
        httpServer.Kill(entireProcessTree: true);
        await httpServer.WaitForExitAsync();
    }
}
