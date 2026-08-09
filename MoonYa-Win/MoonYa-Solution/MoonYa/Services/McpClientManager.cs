using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;
using ModelContextProtocol;
using ModelContextProtocol.Authentication;
using ModelContextProtocol.Client;
using ModelContextProtocol.Protocol;

namespace MoonYa.Services
{
    /// <summary>
    /// Long-lived, per-MoonYa-user MCP client pool. Only stdio and Streamable HTTP
    /// are accepted; the deprecated HTTP+SSE transport is deliberately unavailable.
    /// </summary>
    public sealed class McpClientManager : IAsyncDisposable
    {
        private readonly ConcurrentDictionary<string, McpServerConfig> _configs = new(StringComparer.Ordinal);
        private readonly ConcurrentDictionary<string, ClientEntry> _clients = new(StringComparer.Ordinal);
        private readonly ConcurrentDictionary<string, CancellationTokenSource> _requests = new(StringComparer.Ordinal);
        private readonly SecureTokenVault _vault = new();
        private readonly string _artifactRoot;
        private static readonly JsonSerializerOptions JsonOptions = new()
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            WriteIndented = false
        };

        public event EventHandler<McpToolsChangedEventArgs>? ToolsChanged;

        public McpClientManager()
        {
            _artifactRoot = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "MoonYa",
                "McpArtifacts");
            Directory.CreateDirectory(_artifactRoot);
        }

        public async Task<string> ExecuteAsync(string body)
        {
            try
            {
                using var document = JsonDocument.Parse(string.IsNullOrWhiteSpace(body) ? "{}" : body);
                var root = document.RootElement;
                var action = GetString(root, "action") ?? "";
                var requestId = GetString(root, "request_id") ?? Guid.NewGuid().ToString("N");
                if (action == "cancel")
                {
                    var target = GetString(root, "target_request_id") ?? requestId;
                    var cancelled = _requests.TryGetValue(target, out var active);
                    active?.Cancel();
                    return Json(new { ok = cancelled, content = cancelled ? "请求已取消。" : "请求已结束或不存在。" });
                }

                using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(GetInt(root, "timeout_seconds", 120)));
                _requests[requestId] = cts;
                try
                {
                    return action switch
                    {
                        "configure" => await ConfigureAsync(root, cts.Token).ConfigureAwait(false),
                        "list_tools" or "sync" => await ListToolsAsync(root, cts.Token).ConfigureAwait(false),
                        "call_tool" => await CallToolAsync(root, cts.Token).ConfigureAwait(false),
                        "health" => await HealthAsync(root, cts.Token).ConfigureAwait(false),
                        "disconnect" => await DisconnectAsync(root).ConfigureAwait(false),
                        "revoke" => await RevokeAsync(root).ConfigureAwait(false),
                        "store_secret" => await StoreSecretAsync(root, cts.Token).ConfigureAwait(false),
                        _ => Error("invalid_action", "未知 MCP 操作。")
                    };
                }
                finally
                {
                    _requests.TryRemove(requestId, out _);
                }
            }
            catch (OperationCanceledException)
            {
                return Error("cancelled", "MCP 操作已取消或超时。");
            }
            catch (JsonException ex)
            {
                return Error("invalid_json", ex.Message);
            }
            catch (Exception ex)
            {
                return Error("mcp_error", Redact(ex.Message));
            }
        }

        private async Task<string> ConfigureAsync(JsonElement root, CancellationToken cancellationToken)
        {
            if (!root.TryGetProperty("servers", out var servers) || servers.ValueKind != JsonValueKind.Array)
            {
                return Error("invalid_config", "servers 必须是数组。");
            }

            var changed = 0;
            foreach (var item in servers.EnumerateArray())
            {
                cancellationToken.ThrowIfCancellationRequested();
                var config = McpServerConfig.FromJson(item);
                if (config == null)
                {
                    continue;
                }
                if (_configs.TryGetValue(config.ServerKey, out var previous) && previous.Fingerprint != config.Fingerprint)
                {
                    await DisconnectKeyAsync(config.ServerKey).ConfigureAwait(false);
                }
                _configs[config.ServerKey] = config;
                changed++;
            }
            return Json(new { ok = true, content = $"已加载 {changed} 个 MCP 服务配置。", configured = changed });
        }

        private async Task<string> ListToolsAsync(JsonElement root, CancellationToken cancellationToken)
        {
            var userId = GetLong(root, "user_id");
            var serverKey = RequiredString(root, "server_key");
            var entry = await GetOrConnectAsync(userId, serverKey, cancellationToken).ConfigureAwait(false);
            var client = entry.Client ?? throw new InvalidOperationException("MCP client connection was not initialized.");
            var tools = await client.ListToolsAsync(cancellationToken: cancellationToken).ConfigureAwait(false);
            entry.LastTools = tools.Select(t => t.Name).ToHashSet(StringComparer.Ordinal);
            var payload = tools.Select(t => new
            {
                name = t.ProtocolTool.Name,
                title = t.ProtocolTool.Title ?? t.ProtocolTool.Name,
                description = t.ProtocolTool.Description ?? "",
                inputSchema = JsonSerializer.SerializeToElement(t.ProtocolTool.InputSchema, McpJsonUtilities.DefaultOptions),
                outputSchema = t.ProtocolTool.OutputSchema == null
                    ? (JsonElement?)null
                    : JsonSerializer.SerializeToElement(t.ProtocolTool.OutputSchema, McpJsonUtilities.DefaultOptions),
                annotations = t.ProtocolTool.Annotations == null
                    ? (JsonElement?)null
                    : JsonSerializer.SerializeToElement(t.ProtocolTool.Annotations, McpJsonUtilities.DefaultOptions)
            }).ToArray();
            var tokenMetadata = await ReadTokenMetadataAsync(userId, serverKey, cancellationToken).ConfigureAwait(false);
            return Json(new
            {
                ok = true,
                content = $"发现 {payload.Length} 个 MCP 工具。",
                tools = payload,
                server = entry.ServerMetadata,
                metadata = new
                {
                    source = "mcp",
                    server_key = serverKey,
                    vault_key = _vault.GetVaultKey(userId, serverKey),
                    scopes = tokenMetadata.Scopes,
                    expires_at = tokenMetadata.ExpiresAt
                }
            });
        }

        private async Task<string> CallToolAsync(JsonElement root, CancellationToken cancellationToken)
        {
            var userId = GetLong(root, "user_id");
            var serverKey = RequiredString(root, "server_key");
            var toolName = RequiredString(root, "tool_name");
            var arguments = root.TryGetProperty("arguments", out var args)
                ? ToObjectDictionary(args)
                : new Dictionary<string, object?>();
            var entry = await GetOrConnectAsync(userId, serverKey, cancellationToken).ConfigureAwait(false);
            var client = entry.Client ?? throw new InvalidOperationException("MCP client connection was not initialized.");
            var result = await client.CallToolAsync(
                toolName,
                arguments,
                cancellationToken: cancellationToken).ConfigureAwait(false);
            return await NormalizeToolResultAsync(userId, serverKey, result, cancellationToken).ConfigureAwait(false);
        }

        private async Task<string> HealthAsync(JsonElement root, CancellationToken cancellationToken)
        {
            var userId = GetLong(root, "user_id");
            var serverKey = RequiredString(root, "server_key");
            var started = Stopwatch.StartNew();
            var entry = await GetOrConnectAsync(userId, serverKey, cancellationToken).ConfigureAwait(false);
            var client = entry.Client ?? throw new InvalidOperationException("MCP client connection was not initialized.");
            await client.PingAsync(cancellationToken: cancellationToken).ConfigureAwait(false);
            return Json(new
            {
                ok = true,
                content = "MCP 服务连接正常。",
                latency_ms = started.ElapsedMilliseconds,
                server = entry.ServerMetadata
            });
        }

        private async Task<string> DisconnectAsync(JsonElement root)
        {
            var serverKey = RequiredString(root, "server_key");
            var userId = GetLong(root, "user_id");
            var disconnected = await DisconnectKeyAsync(ClientKey(userId, serverKey)).ConfigureAwait(false);
            return Json(new { ok = true, content = disconnected ? "MCP 连接已关闭。" : "MCP 连接原本未启动。" });
        }

        private async Task<string> RevokeAsync(JsonElement root)
        {
            var serverKey = RequiredString(root, "server_key");
            var userId = GetLong(root, "user_id");
            await DisconnectKeyAsync(ClientKey(userId, serverKey)).ConfigureAwait(false);
            var vaultKey = _vault.GetVaultKey(userId, serverKey);
            var tokens = await _vault.GetJsonAsync<TokenContainer>(vaultKey).ConfigureAwait(false);
            if (tokens != null &&
                _configs.TryGetValue(serverKey, out var config) &&
                Uri.TryCreate(config.RevocationEndpoint, UriKind.Absolute, out var revokeEndpoint) &&
                revokeEndpoint.Scheme == Uri.UriSchemeHttps)
            {
                var token = !string.IsNullOrWhiteSpace(tokens.RefreshToken)
                    ? tokens.RefreshToken
                    : tokens.AccessToken;
                if (!string.IsNullOrWhiteSpace(token))
                {
                    using var client = new System.Net.Http.HttpClient { Timeout = TimeSpan.FromSeconds(20) };
                    var fields = new Dictionary<string, string>
                    {
                        ["token"] = token,
                        ["token_type_hint"] = !string.IsNullOrWhiteSpace(tokens.RefreshToken)
                            ? "refresh_token"
                            : "access_token"
                    };
                    if (!string.IsNullOrWhiteSpace(config.ClientId)) fields["client_id"] = config.ClientId;
                    if (!string.IsNullOrWhiteSpace(config.ClientSecret))
                    {
                        fields["client_secret"] = await ResolveSecretAsync(config.ClientSecret, CancellationToken.None)
                            .ConfigureAwait(false);
                    }
                    using var response = await client.PostAsync(
                        revokeEndpoint,
                        new System.Net.Http.FormUrlEncodedContent(fields)).ConfigureAwait(false);
                    if (!response.IsSuccessStatusCode && response.StatusCode != HttpStatusCode.BadRequest)
                    {
                        throw new InvalidOperationException($"OAuth 撤销端点返回 HTTP {(int)response.StatusCode}。");
                    }
                }
            }
            await _vault.DeleteAsync(vaultKey).ConfigureAwait(false);
            return Json(new
            {
                ok = true,
                content = "本机 OAuth 凭据已撤销。",
                vault_key = vaultKey
            });
        }

        private async Task<string> StoreSecretAsync(JsonElement root, CancellationToken cancellationToken)
        {
            var userId = GetLong(root, "user_id");
            var serverKey = RequiredString(root, "server_key");
            var secretKey = RequiredString(root, "secret_key");
            var secret = RequiredString(root, "secret");
            var vaultKey = $"{_vault.GetVaultKey(userId, serverKey)}:secret:{secretKey}";
            await _vault.StoreJsonAsync(vaultKey, secret, cancellationToken).ConfigureAwait(false);
            return Json(new { ok = true, content = "敏感配置已安全保存。", vault_ref = $"vault://{vaultKey}" });
        }

        private async Task<ClientEntry> GetOrConnectAsync(long userId, string serverKey, CancellationToken cancellationToken)
        {
            if (!_configs.TryGetValue(serverKey, out var config))
            {
                throw new InvalidOperationException($"MCP 服务 {serverKey} 尚未配置到本机。");
            }

            var key = ClientKey(userId, serverKey);
            var entry = _clients.GetOrAdd(key, _ => new ClientEntry());
            await entry.Gate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                if (entry.Client != null && entry.ConfigFingerprint == config.Fingerprint)
                {
                    return entry;
                }
                if (entry.Client != null)
                {
                    await entry.Client.DisposeAsync().ConfigureAwait(false);
                    entry.Client = null;
                }

                var handlers = new McpClientHandlers
                {
                    NotificationHandlers = new Dictionary<string, Func<JsonRpcNotification, CancellationToken, ValueTask>>
                    {
                        [NotificationMethods.ToolListChangedNotification] = (_, _) =>
                        {
                            entry.CatalogDirty = true;
                            ToolsChanged?.Invoke(this, new McpToolsChangedEventArgs(userId, serverKey));
                            return ValueTask.CompletedTask;
                        }
                    }
                };
                var clientOptions = new McpClientOptions
                {
                    InitializationTimeout = TimeSpan.FromSeconds(Math.Clamp(config.TimeoutSeconds, 5, 300)),
                    Handlers = handlers,
                    ClientInfo = new Implementation { Name = "MoonYa", Version = "multi-agent-v1" }
                };
                IClientTransport transport = config.Transport switch
                {
                    "stdio" => await CreateStdioTransportAsync(userId, config, cancellationToken).ConfigureAwait(false),
                    "streamable_http" => await CreateHttpTransportAsync(userId, config, cancellationToken).ConfigureAwait(false),
                    _ => throw new InvalidOperationException("仅支持 stdio 与 Streamable HTTP MCP 传输。")
                };
                entry.Client = await McpClient.CreateAsync(transport, clientOptions, cancellationToken: cancellationToken)
                    .ConfigureAwait(false);
                entry.ConfigFingerprint = config.Fingerprint;
                entry.CatalogDirty = true;
                entry.ServerMetadata = new
                {
                    name = entry.Client.ServerInfo?.Name ?? config.DisplayName,
                    version = entry.Client.ServerInfo?.Version ?? "",
                    instructions = entry.Client.ServerInstructions ?? "",
                    capabilities = JsonSerializer.SerializeToElement(entry.Client.ServerCapabilities, McpJsonUtilities.DefaultOptions)
                };
                return entry;
            }
            catch
            {
                if (entry.Client != null)
                {
                    await entry.Client.DisposeAsync().ConfigureAwait(false);
                    entry.Client = null;
                }
                throw;
            }
            finally
            {
                entry.Gate.Release();
            }
        }

        private async Task<IClientTransport> CreateStdioTransportAsync(
            long userId,
            McpServerConfig config,
            CancellationToken cancellationToken)
        {
            if (string.IsNullOrWhiteSpace(config.CommandPath))
            {
                throw new InvalidOperationException("stdio MCP 缺少 command_path。");
            }
            var environment = new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase);
            foreach (var pair in config.Environment)
            {
                environment[pair.Key] = await ResolveSecretAsync(pair.Value, cancellationToken).ConfigureAwait(false);
            }
            return new StdioClientTransport(new StdioClientTransportOptions
            {
                Name = config.DisplayName,
                Command = config.CommandPath,
                Arguments = config.Arguments,
                WorkingDirectory = config.WorkingDirectory,
                InheritEnvironmentVariables = true,
                EnvironmentVariables = environment,
                ShutdownTimeout = TimeSpan.FromSeconds(5)
            });
        }

        private async Task<IClientTransport> CreateHttpTransportAsync(
            long userId,
            McpServerConfig config,
            CancellationToken cancellationToken)
        {
            if (!Uri.TryCreate(config.Endpoint, UriKind.Absolute, out var endpoint) ||
                (endpoint.Scheme != Uri.UriSchemeHttps &&
                 !(endpoint.Scheme == Uri.UriSchemeHttp &&
                   (endpoint.IsLoopback || endpoint.Host.Equals("localhost", StringComparison.OrdinalIgnoreCase)))))
            {
                throw new InvalidOperationException("远程 Streamable HTTP MCP 必须使用 HTTPS；仅 loopback 允许 HTTP。");
            }

            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var pair in config.Headers)
            {
                headers[pair.Key] = await ResolveSecretAsync(pair.Value, cancellationToken).ConfigureAwait(false);
            }

            ClientOAuthOptions? oauth = null;
            if (config.AuthMode == "oauth")
            {
                var redirectUri = config.RedirectUri;
                if (string.IsNullOrWhiteSpace(redirectUri))
                {
                    redirectUri = $"http://127.0.0.1:{ReserveLoopbackPort()}/oauth/callback/";
                }
                oauth = new ClientOAuthOptions
                {
                    RedirectUri = new Uri(redirectUri),
                    ClientId = config.ClientId,
                    ClientSecret = string.IsNullOrWhiteSpace(config.ClientSecret)
                        ? null
                        : await ResolveSecretAsync(config.ClientSecret, cancellationToken).ConfigureAwait(false),
                    ClientMetadataDocumentUri = string.IsNullOrWhiteSpace(config.ClientMetadataDocumentUri)
                        ? null
                        : new Uri(config.ClientMetadataDocumentUri),
                    Scopes = config.Scopes,
                    TokenCache = new DpapiTokenCache(_vault, _vault.GetVaultKey(userId, config.ServerKey)),
                    AuthorizationRedirectDelegate = HandleAuthorizationRedirectAsync
                };
            }

            return new HttpClientTransport(new HttpClientTransportOptions
            {
                Name = config.DisplayName,
                Endpoint = endpoint,
                TransportMode = HttpTransportMode.StreamableHttp,
                ConnectionTimeout = TimeSpan.FromSeconds(Math.Clamp(config.TimeoutSeconds, 5, 300)),
                MaxReconnectionAttempts = Math.Clamp(config.ReconnectAttempts, 0, 10),
                AdditionalHeaders = headers,
                OAuth = oauth
            });
        }

        private static async Task<string?> HandleAuthorizationRedirectAsync(
            Uri authorizationUri,
            Uri redirectUri,
            CancellationToken cancellationToken)
        {
            if (!redirectUri.IsLoopback || redirectUri.Scheme != Uri.UriSchemeHttp)
            {
                throw new InvalidOperationException("OAuth 回调必须使用精确的 HTTP loopback URI。");
            }

            var expectedState = ParseQuery(authorizationUri.Query).GetValueOrDefault("state") ?? "";
            using var listener = new HttpListener();
            listener.Prefixes.Add(redirectUri.AbsoluteUri.EndsWith("/", StringComparison.Ordinal)
                ? redirectUri.AbsoluteUri
                : redirectUri.AbsoluteUri + "/");
            listener.Start();
            Process.Start(new ProcessStartInfo(authorizationUri.AbsoluteUri) { UseShellExecute = true });
            using var registration = cancellationToken.Register(() =>
            {
                try { listener.Stop(); } catch { }
            });
            var context = await listener.GetContextAsync().WaitAsync(cancellationToken).ConfigureAwait(false);
            var values = ParseQuery(context.Request.Url?.Query ?? "");
            var state = values.GetValueOrDefault("state") ?? "";
            var code = values.GetValueOrDefault("code");
            var error = values.GetValueOrDefault("error");
            var valid = ValidateOAuthCallback(expectedState, state, code);
            var html = valid
                ? "<!doctype html><meta charset=utf-8><title>MoonYa</title><body style='font:16px system-ui;background:#101522;color:#eef;padding:40px'>MoonYa 已完成授权，可以关闭此窗口。</body>"
                : "<!doctype html><meta charset=utf-8><title>MoonYa</title><body style='font:16px system-ui;background:#241015;color:#fee;padding:40px'>授权失败，请返回 MoonYa 重试。</body>";
            var bytes = Encoding.UTF8.GetBytes(html);
            context.Response.ContentType = "text/html; charset=utf-8";
            context.Response.ContentLength64 = bytes.Length;
            await context.Response.OutputStream.WriteAsync(bytes, cancellationToken).ConfigureAwait(false);
            context.Response.Close();
            listener.Stop();
            if (!valid)
            {
                throw new InvalidOperationException($"OAuth state 校验失败或授权被拒绝：{error ?? "missing_code"}");
            }
            return code;
        }

        internal static bool ValidateOAuthCallback(string expectedState, string actualState, string? code) =>
            !string.IsNullOrEmpty(code) &&
            (string.IsNullOrEmpty(expectedState) || CryptographicEquals(expectedState, actualState));

        private async Task<string> NormalizeToolResultAsync(
            long userId,
            string serverKey,
            CallToolResult result,
            CancellationToken cancellationToken)
        {
            var text = new List<string>();
            var artifacts = new List<object>();
            foreach (var block in result.Content)
            {
                cancellationToken.ThrowIfCancellationRequested();
                switch (block)
                {
                    case TextContentBlock textBlock:
                        text.Add(textBlock.Text);
                        break;
                    case ImageContentBlock image:
                        artifacts.Add(await SaveBinaryArtifactAsync(
                            userId, serverKey, image.DecodedData.ToArray(), image.MimeType, "image", cancellationToken));
                        break;
                    case AudioContentBlock audio:
                        artifacts.Add(await SaveBinaryArtifactAsync(
                            userId, serverKey, audio.DecodedData.ToArray(), audio.MimeType, "audio", cancellationToken));
                        break;
                    case ResourceLinkBlock link:
                        artifacts.Add(new
                        {
                            uri = link.Uri,
                            name = link.Name,
                            title = link.Title,
                            mime_type = link.MimeType,
                            size = link.Size,
                            kind = "resource"
                        });
                        break;
                    case EmbeddedResourceBlock embedded:
                        artifacts.Add(new
                        {
                            kind = "embedded_resource",
                            resource = JsonSerializer.SerializeToElement(embedded.Resource, McpJsonUtilities.DefaultOptions)
                        });
                        break;
                    default:
                        text.Add(JsonSerializer.Serialize(block, McpJsonUtilities.DefaultOptions));
                        break;
                }
            }

            var isError = result.IsError == true;
            var content = text.Count == 0
                ? (isError ? "MCP 工具执行失败。" : "MCP 工具执行完成。")
                : string.Join("\n", text);
            var tokenMetadata = await ReadTokenMetadataAsync(userId, serverKey, cancellationToken).ConfigureAwait(false);
            return Json(new
            {
                ok = !isError,
                content,
                structured_content = result.StructuredContent,
                artifacts,
                metadata = new
                {
                    source = "mcp",
                    server_key = serverKey,
                    vault_key = _vault.GetVaultKey(userId, serverKey),
                    scopes = tokenMetadata.Scopes,
                    expires_at = tokenMetadata.ExpiresAt
                },
                error = isError ? new { code = "mcp_tool_error", message = content } : null
            });
        }

        private async Task<object> SaveBinaryArtifactAsync(
            long userId,
            string serverKey,
            byte[] data,
            string mimeType,
            string kind,
            CancellationToken cancellationToken)
        {
            var directory = Path.Combine(_artifactRoot, userId.ToString(), SafeSegment(serverKey));
            Directory.CreateDirectory(directory);
            var extension = MimeExtension(mimeType);
            var path = Path.Combine(directory, Guid.NewGuid().ToString("N") + extension);
            await File.WriteAllBytesAsync(path, data, cancellationToken).ConfigureAwait(false);
            return new { uri = path, local_path = path, mime_type = mimeType, kind, size = data.LongLength };
        }

        private async Task<string> ResolveSecretAsync(string value, CancellationToken cancellationToken)
        {
            if (!value.StartsWith("vault://", StringComparison.Ordinal))
            {
                return value;
            }
            var secret = await _vault.GetJsonAsync<string>(value.Substring("vault://".Length), cancellationToken)
                .ConfigureAwait(false);
            if (secret == null)
            {
                throw new InvalidOperationException("指定的本机秘密不存在或无法解密。");
            }
            return secret;
        }

        private async Task<(string[] Scopes, string? ExpiresAt)> ReadTokenMetadataAsync(
            long userId,
            string serverKey,
            CancellationToken cancellationToken)
        {
            var tokens = await _vault.GetJsonAsync<TokenContainer>(
                _vault.GetVaultKey(userId, serverKey),
                cancellationToken).ConfigureAwait(false);
            if (tokens == null)
            {
                return (Array.Empty<string>(), null);
            }
            var json = JsonSerializer.SerializeToElement(tokens, JsonOptions);
            var scope = json.TryGetProperty("scope", out var scopeNode) && scopeNode.ValueKind == JsonValueKind.String
                ? scopeNode.GetString() ?? ""
                : "";
            string? expiresAt = null;
            if (json.TryGetProperty("expiresIn", out var expiresNode) &&
                expiresNode.TryGetInt64(out var expiresIn) &&
                json.TryGetProperty("obtainedAt", out var obtainedNode) &&
                obtainedNode.ValueKind == JsonValueKind.String &&
                DateTimeOffset.TryParse(obtainedNode.GetString(), out var obtainedAt))
            {
                expiresAt = obtainedAt.AddSeconds(expiresIn).UtcDateTime.ToString("O");
            }
            return (
                scope.Split(' ', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries),
                expiresAt);
        }

        private async Task<bool> DisconnectKeyAsync(string key)
        {
            if (!_clients.TryRemove(key, out var entry))
            {
                // A server-key-only invalidation is used when its shared config changes.
                var matching = _clients.Keys.Where(k => k.EndsWith("|" + key, StringComparison.Ordinal)).ToArray();
                var any = false;
                foreach (var item in matching)
                {
                    any |= await DisconnectKeyAsync(item).ConfigureAwait(false);
                }
                return any;
            }
            if (entry.Client != null)
            {
                await entry.Client.DisposeAsync().ConfigureAwait(false);
                entry.Client = null;
            }
            entry.Gate.Dispose();
            return true;
        }

        public async ValueTask DisposeAsync()
        {
            foreach (var request in _requests.Values)
            {
                request.Cancel();
            }
            foreach (var key in _clients.Keys.ToArray())
            {
                await DisconnectKeyAsync(key).ConfigureAwait(false);
            }
        }

        private static Dictionary<string, object?> ToObjectDictionary(JsonElement element)
        {
            if (element.ValueKind != JsonValueKind.Object)
            {
                return new Dictionary<string, object?>();
            }
            return element.EnumerateObject().ToDictionary(
                property => property.Name,
                property => JsonValueToObject(property.Value),
                StringComparer.Ordinal);
        }

        private static object? JsonValueToObject(JsonElement element) => element.ValueKind switch
        {
            JsonValueKind.Object => element.EnumerateObject().ToDictionary(
                p => p.Name, p => JsonValueToObject(p.Value), StringComparer.Ordinal),
            JsonValueKind.Array => element.EnumerateArray().Select(JsonValueToObject).ToArray(),
            JsonValueKind.String => element.GetString(),
            JsonValueKind.Number when element.TryGetInt64(out var number) => number,
            JsonValueKind.Number => element.GetDouble(),
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            _ => null
        };

        private static Dictionary<string, string> ParseQuery(string query)
        {
            var result = new Dictionary<string, string>(StringComparer.Ordinal);
            foreach (var pair in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
            {
                var parts = pair.Split('=', 2);
                result[Uri.UnescapeDataString(parts[0].Replace("+", " "))] =
                    parts.Length > 1 ? Uri.UnescapeDataString(parts[1].Replace("+", " ")) : "";
            }
            return result;
        }

        private static bool CryptographicEquals(string left, string right)
        {
            var leftBytes = Encoding.UTF8.GetBytes(left);
            var rightBytes = Encoding.UTF8.GetBytes(right);
            return leftBytes.Length == rightBytes.Length &&
                   System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(leftBytes, rightBytes);
        }

        private static int ReserveLoopbackPort()
        {
            var listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            var port = ((IPEndPoint)listener.LocalEndpoint).Port;
            listener.Stop();
            return port;
        }

        private static string ClientKey(long userId, string serverKey) => $"{userId}|{serverKey}";
        private static string SafeSegment(string value) =>
            string.Concat(value.Select(character => char.IsLetterOrDigit(character) || character is '-' or '_' ? character : '_'));
        private static string MimeExtension(string mime) => mime.ToLowerInvariant() switch
        {
            "image/png" => ".png",
            "image/jpeg" => ".jpg",
            "image/gif" => ".gif",
            "image/webp" => ".webp",
            "audio/mpeg" => ".mp3",
            "audio/wav" => ".wav",
            "audio/ogg" => ".ogg",
            _ => ".bin"
        };

        private static string RequiredString(JsonElement element, string property) =>
            GetString(element, property) is { Length: > 0 } value
                ? value
                : throw new InvalidOperationException($"{property} 不能为空。");
        private static string? GetString(JsonElement element, string property) =>
            element.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.String
                ? value.GetString()
                : null;
        private static long GetLong(JsonElement element, string property) =>
            element.TryGetProperty(property, out var value) && value.TryGetInt64(out var number) && number > 0
                ? number
                : throw new InvalidOperationException($"{property} 必须是正整数。");
        private static int GetInt(JsonElement element, string property, int fallback) =>
            element.TryGetProperty(property, out var value) && value.TryGetInt32(out var number)
                ? number
                : fallback;
        private static string Json(object value) => JsonSerializer.Serialize(value, JsonOptions);
        private static string Error(string code, string message) =>
            Json(new
            {
                ok = false,
                content = message,
                structured_content = (object?)null,
                artifacts = Array.Empty<object>(),
                metadata = new { source = "mcp" },
                error = new { code, message }
            });
        private static string Redact(string message) =>
            System.Text.RegularExpressions.Regex.Replace(
                message,
                @"(?i)(bearer\s+|access[_-]?token[=:]\s*|refresh[_-]?token[=:]\s*|client[_-]?secret[=:]\s*)[^\s,;""']+",
                "$1***");

        private sealed class ClientEntry
        {
            public SemaphoreSlim Gate { get; } = new(1, 1);
            public McpClient? Client { get; set; }
            public string ConfigFingerprint { get; set; } = "";
            public bool CatalogDirty { get; set; }
            public HashSet<string> LastTools { get; set; } = new(StringComparer.Ordinal);
            public object? ServerMetadata { get; set; }
        }
    }

    public sealed class McpToolsChangedEventArgs : EventArgs
    {
        public McpToolsChangedEventArgs(long userId, string serverKey)
        {
            UserId = userId;
            ServerKey = serverKey;
        }
        public long UserId { get; }
        public string ServerKey { get; }
    }

    internal sealed class McpServerConfig
    {
        public string ServerKey { get; init; } = "";
        public string DisplayName { get; init; } = "";
        public string Transport { get; init; } = "";
        public string? Endpoint { get; init; }
        public string? CommandPath { get; init; }
        public List<string> Arguments { get; init; } = new();
        public Dictionary<string, string> Environment { get; init; } = new(StringComparer.OrdinalIgnoreCase);
        public Dictionary<string, string> Headers { get; init; } = new(StringComparer.OrdinalIgnoreCase);
        public string? WorkingDirectory { get; init; }
        public string AuthMode { get; init; } = "none";
        public string? ClientId { get; init; }
        public string? ClientSecret { get; init; }
        public string? ClientMetadataDocumentUri { get; init; }
        public string? RedirectUri { get; init; }
        public string? RevocationEndpoint { get; init; }
        public List<string> Scopes { get; init; } = new();
        public int TimeoutSeconds { get; init; } = 60;
        public int ReconnectAttempts { get; init; } = 3;
        public string Fingerprint { get; init; } = "";

        public static McpServerConfig? FromJson(JsonElement source)
        {
            var serverKey = String(source, "server_key");
            var transport = String(source, "transport");
            if (string.IsNullOrWhiteSpace(serverKey) ||
                (transport != "stdio" && transport != "streamable_http"))
            {
                return null;
            }
            var oauth = Object(source, "oauth_config_json");
            var environment = StringDictionary(Object(source, "environment_json"));
            var arguments = StringList(source, "arguments_json");
            var headers = StringDictionary(Object(oauth, "headers"));
            var fingerprintPayload = JsonSerializer.Serialize(new
            {
                serverKey,
                transport,
                endpoint = String(source, "endpoint"),
                commandPath = String(source, "command_path"),
                arguments,
                environment,
                headers,
                workingDirectory = String(oauth, "working_directory"),
                authMode = String(source, "auth_mode") ?? "none",
                clientId = String(oauth, "client_id"),
                clientSecret = String(oauth, "client_secret"),
                clientMetadataDocumentUri = String(oauth, "client_metadata_document_uri"),
                redirectUri = String(oauth, "redirect_uri"),
                revocationEndpoint = String(oauth, "revocation_endpoint"),
                scopes = StringList(oauth, "scopes"),
                timeoutSeconds = Int(oauth, "timeout_seconds", 60),
                reconnectAttempts = Int(oauth, "reconnect_attempts", 3),
            });
            return new McpServerConfig
            {
                ServerKey = serverKey,
                DisplayName = String(source, "display_name") ?? serverKey,
                Transport = transport,
                Endpoint = String(source, "endpoint"),
                CommandPath = String(source, "command_path"),
                Arguments = arguments,
                Environment = environment,
                Headers = headers,
                WorkingDirectory = String(oauth, "working_directory"),
                AuthMode = String(source, "auth_mode") ?? "none",
                ClientId = String(oauth, "client_id"),
                ClientSecret = String(oauth, "client_secret"),
                ClientMetadataDocumentUri = String(oauth, "client_metadata_document_uri"),
                RedirectUri = String(oauth, "redirect_uri"),
                RevocationEndpoint = String(oauth, "revocation_endpoint"),
                Scopes = StringList(oauth, "scopes"),
                TimeoutSeconds = Int(oauth, "timeout_seconds", 60),
                ReconnectAttempts = Int(oauth, "reconnect_attempts", 3),
                Fingerprint = Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(Encoding.UTF8.GetBytes(fingerprintPayload)))
            };
        }

        private static JsonElement Object(JsonElement source, string property) =>
            source.ValueKind == JsonValueKind.Object &&
            source.TryGetProperty(property, out var value) &&
            value.ValueKind == JsonValueKind.Object
                ? value
                : default;
        private static string? String(JsonElement source, string property) =>
            source.ValueKind == JsonValueKind.Object &&
            source.TryGetProperty(property, out var value) &&
            value.ValueKind == JsonValueKind.String
                ? value.GetString()
                : null;
        private static int Int(JsonElement source, string property, int fallback) =>
            source.ValueKind == JsonValueKind.Object &&
            source.TryGetProperty(property, out var value) &&
            value.TryGetInt32(out var number)
                ? number
                : fallback;
        private static List<string> StringList(JsonElement source, string property)
        {
            if (source.ValueKind != JsonValueKind.Object ||
                !source.TryGetProperty(property, out var value) ||
                value.ValueKind != JsonValueKind.Array)
            {
                return new List<string>();
            }
            return value.EnumerateArray()
                .Where(item => item.ValueKind == JsonValueKind.String)
                .Select(item => item.GetString() ?? "")
                .Where(item => item.Length > 0)
                .ToList();
        }
        private static Dictionary<string, string> StringDictionary(JsonElement source)
        {
            if (source.ValueKind != JsonValueKind.Object)
            {
                return new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            }
            return source.EnumerateObject()
                .Where(property => property.Value.ValueKind == JsonValueKind.String &&
                                   property.Value.GetString() != "***")
                .ToDictionary(
                    property => property.Name,
                    property => property.Value.GetString() ?? "",
                    StringComparer.OrdinalIgnoreCase);
        }
    }
}