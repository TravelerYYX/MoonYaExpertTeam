using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>Loopback-only HTTP adapter for the versioned browser contract.</summary>
    public sealed class BrowserApiServer
    {
        private readonly BrowserAutomationService _service;
        private readonly BrowserSecurityGate _securityGate;
        private readonly string _loopbackHost;
        private readonly int _port;
        private HttpListener? _listener;
        private CancellationTokenSource? _cts;
        private Task? _listenTask;
        private int _screenshotIndex;

        public int Port => _port;
        public bool IsRunning => _listener?.IsListening ?? false;

        public BrowserApiServer(
            BrowserAutomationService service,
            BrowserSecurityGate securityGate,
            string loopbackHost,
            int port)
        {
            _service = service ?? throw new ArgumentNullException(nameof(service));
            _securityGate = securityGate ?? throw new ArgumentNullException(nameof(securityGate));
            _loopbackHost = string.IsNullOrWhiteSpace(loopbackHost)
                ? throw new ArgumentException("loopbackHost 不能为空", nameof(loopbackHost))
                : loopbackHost;
            _port = port > 0 ? port : throw new ArgumentOutOfRangeException(nameof(port));
        }

        public Task StartAsync()
        {
            if (IsRunning) return Task.CompletedTask;
            _cts = new CancellationTokenSource();
            _listener = new HttpListener();
            _listener.Prefixes.Add($"http://{_loopbackHost}:{_port}/");
            try
            {
                _listener.Start();
            }
            catch (HttpListenerException ex)
            {
                System.Diagnostics.Debug.WriteLine($"BrowserApiServer start failed: {ex.Message}");
                return Task.CompletedTask;
            }
            _listenTask = Task.Run(() => ListenLoop(_cts.Token));
            return Task.CompletedTask;
        }

        public async Task StopAsync()
        {
            _cts?.Cancel();
            try { _listener?.Stop(); } catch (Exception) { }
            try { _listener?.Close(); } catch (Exception) { }
            if (_listenTask != null)
            {
                try { await _listenTask; } catch (Exception) { }
            }
        }

        private async Task ListenLoop(CancellationToken cancellationToken)
        {
            while (!cancellationToken.IsCancellationRequested && _listener?.IsListening == true)
            {
                try
                {
                    var contextTask = _listener.GetContextAsync();
                    var completed = await Task.WhenAny(contextTask, Task.Delay(Timeout.Infinite, cancellationToken));
                    if (completed != contextTask || cancellationToken.IsCancellationRequested) break;
                    var context = await contextTask;
                    _ = Task.Run(() => HandleRequest(context), cancellationToken);
                }
                catch (OperationCanceledException) { break; }
                catch (HttpListenerException) { break; }
                catch (Exception ex) { System.Diagnostics.Debug.WriteLine($"BrowserApiServer: {ex.Message}"); }
            }
        }

        private async Task HandleRequest(HttpListenerContext context)
        {
            var request = context.Request;
            var response = context.Response;
            try
            {
                if (request.HttpMethod == "OPTIONS")
                {
                    response.StatusCode = 204;
                    response.Close();
                    return;
                }
                var remote = request.RemoteEndPoint?.Address;
                if (remote == null || (!IPAddress.IsLoopback(remote)))
                {
                    await Respond(response, Error(BrowserProtocol.Errors.InvalidRequest, "只允许本地访问"));
                    return;
                }

                var body = string.Empty;
                if (request.HasEntityBody)
                {
                    using var reader = new StreamReader(request.InputStream, request.ContentEncoding);
                    body = await reader.ReadToEndAsync();
                }
                var path = request.Url?.AbsolutePath ?? string.Empty;
                var method = request.HttpMethod;

                if (path == BrowserProtocol.ServiceManifestRoute && (method == "GET" || method == "POST"))
                {
                    await Respond(response, new
                    {
                        success = true,
                        base_route = BrowserProtocol.RoutePrefix,
                        execute_route = BrowserProtocol.ExecuteRoute,
                        authorize_route = BrowserProtocol.AuthorizeRoute,
                        actions = BrowserProtocol.Actions.Public.OrderBy(value => value).ToArray(),
                    });
                    return;
                }
                if (path == BrowserProtocol.AuthorizeRoute && method == "POST")
                {
                    var requestBody = ParseBody(body);
                    var operation = GetString(requestBody, "operation");
                    if (operation == "list_permissions")
                    {
                        await Respond(response, new { success = true, permissions = _securityGate.ListPersistentPermissions() });
                        return;
                    }
                    if (operation == "revoke_permission")
                    {
                        var revoked = _securityGate.RevokeSessionPermission(
                            GetString(requestBody, "domain"),
                            GetString(requestBody, "user_context"));
                        await Respond(response, new
                        {
                            success = revoked,
                            error_code = revoked ? null : BrowserProtocol.Errors.InvalidRequest,
                            error = revoked ? null : "站点权限不存在或无法撤销。",
                        });
                        return;
                    }
                    var result = _securityGate.Decide(
                        GetString(requestBody, "approval_token"),
                        GetString(requestBody, "decision"),
                        GetString(requestBody, "user_context"),
                        GetString(requestBody, "session_id"),
                        GetLong(requestBody, "page_version"),
                        GetString(requestBody, "action"));
                    await Respond(response, AuthorizationResponse(result));
                    return;
                }
                if (path == BrowserProtocol.ExecuteRoute && method == "POST")
                {
                    await Execute(response, ParseBody(body));
                    return;
                }

                var legacyAction = LegacyAction(path);
                if (legacyAction != null && (method == "GET" || method == "POST"))
                {
                    var legacyBody = ParseBody(body);
                    using var doc = JsonDocument.Parse(MergeAction(legacyBody, legacyAction));
                    await Execute(response, doc.RootElement);
                    return;
                }
                await Respond(response, Error(BrowserProtocol.Errors.InvalidRequest, $"未知路由: {method} {path}"));
            }
            catch (Exception ex)
            {
                await Respond(response, Error(ErrorCode(ex), CleanError(ex.Message)));
            }
        }

        private async Task Execute(HttpListenerResponse response, JsonElement request)
        {
            var action = GetString(request, "action").Trim().ToLowerInvariant();
            if (!BrowserProtocol.Actions.Public.Contains(action))
            {
                await Respond(response, Error(BrowserProtocol.Errors.UnknownAction, "未知浏览器动作: " + action));
                return;
            }

            var url = GetString(request, "url");
            var riskCategory = GetString(request, "risk_category");
            var approvalToken = GetString(request, "approval_token");
            var userContext = GetString(request, "user_context");
            var selector = string.Empty;
            var hasElementTarget = !string.IsNullOrWhiteSpace(GetString(request, "element_id"))
                || !string.IsNullOrWhiteSpace(GetString(request, "selector"));
            if (RequiresElement(action) || (action == BrowserProtocol.Actions.Press && hasElementTarget))
            {
                selector = _service.ResolveElementTarget(GetString(request, "element_id"), GetString(request, "selector"));
                var inferredRisk = await _service.ClassifySensitiveActionAsync(
                    selector, action, GetString(request, "key"));
                if (!string.Equals(inferredRisk, "none", StringComparison.Ordinal))
                {
                    riskCategory = inferredRisk;
                }
            }
            var authorizationUrl = action is BrowserProtocol.Actions.Start or BrowserProtocol.Actions.Navigate or BrowserProtocol.Actions.NewTab
                ? url
                : _service.CurrentUrl;
            if (!string.IsNullOrWhiteSpace(authorizationUrl)
                && (action is BrowserProtocol.Actions.Start
                    or BrowserProtocol.Actions.Navigate
                    or BrowserProtocol.Actions.NewTab
                    or BrowserProtocol.Actions.Click
                    or BrowserProtocol.Actions.Press
                    or BrowserProtocol.Actions.Check
                    or BrowserProtocol.Actions.Uncheck))
            {
                var authorization = await _securityGate.CheckAsync(
                    authorizationUrl,
                    riskCategory,
                    approvalToken,
                    userContext,
                    _service.SessionId,
                    _service.PageVersion,
                    action);
                if (!authorization.Allowed)
                {
                    await Respond(response, AuthorizationResponse(authorization));
                    return;
                }
            }

            var beforeUrl = _service.CurrentUrl;
            var beforePageVersion = _service.PageVersion;
            var beforeFactsFingerprint = _service.IsRunning
                ? await _service.GetPageFactsFingerprintAsync()
                : string.Empty;
            var screenshot = string.Empty;
            object? actionData = null;

            switch (action)
            {
                case BrowserProtocol.Actions.Start:
                    await _service.StartAsync(url);
                    break;
                case BrowserProtocol.Actions.Status:
                    break;
                case BrowserProtocol.Actions.Stop:
                    await _service.StopAsync();
                    _securityGate.ClearSession();
                    break;
                case BrowserProtocol.Actions.Navigate:
                    Require(url, "url");
                    await _service.NavigateAsync(url);
                    break;
                case BrowserProtocol.Actions.Back:
                    await _service.BackAsync();
                    break;
                case BrowserProtocol.Actions.Forward:
                    await _service.ForwardAsync();
                    break;
                case BrowserProtocol.Actions.Reload:
                    await _service.ReloadAsync();
                    break;
                case BrowserProtocol.Actions.Inspect:
                    break;
                case BrowserProtocol.Actions.Screenshot:
                    break;
                case BrowserProtocol.Actions.Click:
                    await _service.ClickAsync(selector);
                    break;
                case BrowserProtocol.Actions.Fill:
                    await _service.FillAsync(selector, GetString(request, "text"));
                    break;
                case BrowserProtocol.Actions.Hover:
                    await _service.HoverAsync(selector);
                    break;
                case BrowserProtocol.Actions.Press:
                    var key = GetString(request, "key");
                    Require(key, "key");
                    await _service.PressAsync(string.IsNullOrWhiteSpace(selector) ? null : selector, key);
                    break;
                case BrowserProtocol.Actions.Select:
                    actionData = new { selected_values = await _service.SelectAsync(selector, GetStringArray(request, "values")) };
                    break;
                case BrowserProtocol.Actions.Check:
                    await _service.SetCheckedAsync(selector, true);
                    break;
                case BrowserProtocol.Actions.Uncheck:
                    await _service.SetCheckedAsync(selector, false);
                    break;
                case BrowserProtocol.Actions.Scroll:
                    await _service.ScrollAsync(DefaultString(GetString(request, "direction"), "down"), GetInt(request, "amount", _service.DefaultScrollAmount));
                    break;
                case BrowserProtocol.Actions.Wait:
                    var waitSelector = string.Empty;
                    if (!string.IsNullOrWhiteSpace(GetString(request, "element_id")) || !string.IsNullOrWhiteSpace(GetString(request, "selector")))
                    {
                        waitSelector = _service.ResolveElementTarget(GetString(request, "element_id"), GetString(request, "selector"));
                    }
                    await _service.WaitForConditionAsync(
                        DefaultString(GetString(request, "condition"), "time"),
                        GetInt(request, "ms", _service.DefaultWaitMs),
                        waitSelector,
                        GetString(request, "text"),
                        GetString(request, "url"),
                        GetString(request, "state"));
                    break;
                case BrowserProtocol.Actions.NewTab:
                    actionData = new { tab_id = await _service.NewTabAsync(url) };
                    break;
                case BrowserProtocol.Actions.ListTabs:
                    actionData = new { tabs = await _service.ListTabsAsync() };
                    break;
                case BrowserProtocol.Actions.SwitchTab:
                    var switchId = GetString(request, "tab_id");
                    Require(switchId, "tab_id");
                    await _service.SwitchTabAsync(switchId);
                    break;
                case BrowserProtocol.Actions.CloseTab:
                    await _service.CloseTabAsync(GetString(request, "tab_id"));
                    break;
                case BrowserProtocol.Actions.ListDownloads:
                    actionData = new { downloads = await _service.ListDownloadsAsync() };
                    break;
            }

            var browserStatus = await _service.GetStatusAsync();
            var pageText = string.Empty;
            var pageVersion = _service.PageVersion;
            object[] elements = Array.Empty<object>();
            object? focusedElement = null;
            if (_service.IsRunning && action is not BrowserProtocol.Actions.Status
                and not BrowserProtocol.Actions.ListTabs and not BrowserProtocol.Actions.ListDownloads)
            {
                var inspectionJson = await _service.GetElementsAsync();
                using var inspectionDoc = JsonDocument.Parse(inspectionJson);
                var inspection = inspectionDoc.RootElement;
                pageText = GetString(inspection, "page_text");
                pageVersion = GetLong(inspection, "page_version");
                elements = inspection.TryGetProperty("elements", out var elementArray)
                    ? elementArray.EnumerateArray().Select(item => (object)item.Clone()).ToArray()
                    : Array.Empty<object>();
                foreach (var item in elements.OfType<JsonElement>())
                {
                    if (!item.TryGetProperty("focused", out var focused) || focused.ValueKind != JsonValueKind.True) continue;
                    focusedElement = item;
                    break;
                }
            }

            if (_service.IsRunning && ShouldScreenshot(action))
            {
                screenshot = await _service.ScreenshotBase64Async();
                _screenshotIndex++;
            }

            var currentUrl = _service.CurrentUrl;
            var afterFactsFingerprint = _service.IsRunning
                ? await _service.GetPageFactsFingerprintAsync()
                : string.Empty;
            var pageChanged = !string.Equals(beforeUrl, currentUrl, StringComparison.Ordinal)
                || (!string.IsNullOrEmpty(beforeFactsFingerprint)
                    && !string.Equals(beforeFactsFingerprint, afterFactsFingerprint, StringComparison.Ordinal));
            await Respond(response, new
            {
                success = true,
                error_code = (string?)null,
                action,
                browser = browserStatus,
                page_url = currentUrl,
                page_title = await CurrentTitleAsync(),
                page_version = pageVersion,
                page_text = pageText,
                dom_elements = elements,
                focused_element = focusedElement,
                page_changed = pageChanged,
                change_hint = ChangeHint(beforeUrl, currentUrl, action),
                change_evidence = new
                {
                    before_url = beforeUrl,
                    after_url = currentUrl,
                    before_page_version = beforePageVersion,
                    after_page_version = pageVersion,
                    facts_changed = pageChanged,
                    before_fingerprint = string.IsNullOrEmpty(beforeFactsFingerprint) ? null : beforeFactsFingerprint,
                    after_fingerprint = string.IsNullOrEmpty(afterFactsFingerprint) ? null : afterFactsFingerprint,
                },
                screenshot = string.IsNullOrWhiteSpace(screenshot) ? null : screenshot,
                index = string.IsNullOrWhiteSpace(screenshot) ? (int?)null : _screenshotIndex,
                data = actionData,
            });
        }

        private async Task<string> CurrentTitleAsync()
        {
            if (!_service.IsRunning) return string.Empty;
            var statusJson = JsonSerializer.Serialize(await _service.GetStatusAsync());
            using var doc = JsonDocument.Parse(statusJson);
            return GetString(doc.RootElement, "title");
        }

        private static bool RequiresElement(string action) => action is
            BrowserProtocol.Actions.Click or BrowserProtocol.Actions.Fill or BrowserProtocol.Actions.Hover
            or BrowserProtocol.Actions.Select or BrowserProtocol.Actions.Check or BrowserProtocol.Actions.Uncheck;

        private static bool ShouldScreenshot(string action) => action is not
            BrowserProtocol.Actions.Status and not BrowserProtocol.Actions.Stop and not BrowserProtocol.Actions.ListTabs
            and not BrowserProtocol.Actions.ListDownloads and not BrowserProtocol.Actions.Wait;

        private static string? LegacyAction(string path)
        {
            if (string.Equals(path, BrowserProtocol.LegacyInspectRoute, StringComparison.Ordinal))
            {
                return BrowserProtocol.Actions.Inspect;
            }
            return BrowserProtocol.Actions.Public.FirstOrDefault(action =>
                string.Equals(path, BrowserProtocol.LegacyRoute(action), StringComparison.Ordinal));
        }

        private object AuthorizationResponse(BrowserAuthorizationChallenge result) => new
        {
            success = result.Allowed,
            error_code = result.Allowed ? null : result.ErrorCode,
            error = result.Allowed ? null : result.Reason,
            approval_required = !result.Allowed && !result.Denied,
            approval_token = result.ApprovalToken,
            approval_kind = result.ApprovalKind,
            url = result.Url,
            domain = result.Domain,
            reason = result.Reason,
            certificate_fingerprint = result.CertificateFingerprint,
            risk_category = result.RiskCategory,
            action = result.Action,
            page_version = result.PageVersion,
            browser = BrowserState(),
            page_url = _service.CurrentUrl,
            authorize_route = BrowserProtocol.AuthorizeRoute,
        };

        private object Error(string errorCode, string message) => new
        {
            success = false,
            error_code = errorCode,
            error = message,
            browser = BrowserState(),
            page_url = _service.CurrentUrl,
            page_version = _service.PageVersion,
        };

        private object BrowserState() => new
        {
            running = _service.IsRunning,
            session_id = _service.SessionId,
            page_version = _service.PageVersion,
            tab_id = _service.CurrentTabId,
        };

        private static string ErrorCode(Exception exception)
        {
            var message = exception.Message ?? string.Empty;
            if (message.StartsWith(BrowserProtocol.Errors.StaleElement + ":", StringComparison.Ordinal)) return BrowserProtocol.Errors.StaleElement;
            if (message.StartsWith(BrowserProtocol.Errors.ElementNotFound + ":", StringComparison.Ordinal)) return BrowserProtocol.Errors.ElementNotFound;
            if (message.StartsWith(BrowserProtocol.Errors.BrowserLaunchFailed + ":", StringComparison.Ordinal)) return BrowserProtocol.Errors.BrowserLaunchFailed;
            if (exception is TimeoutException) return BrowserProtocol.Errors.Timeout;
            if (exception is ArgumentException or JsonException) return BrowserProtocol.Errors.InvalidRequest;
            if (message.Contains("浏览器未启动", StringComparison.Ordinal)) return BrowserProtocol.Errors.BrowserNotStarted;
            if (message.Contains("元素", StringComparison.Ordinal) && message.Contains("未找到", StringComparison.Ordinal)) return BrowserProtocol.Errors.ElementNotFound;
            return BrowserProtocol.Errors.InternalError;
        }

        private static string CleanError(string message)
        {
            var separator = message.IndexOf(':');
            return separator > 0 && separator < 32 ? message[(separator + 1)..].Trim() : message;
        }

        private static string ChangeHint(string beforeUrl, string afterUrl, string action)
        {
            if (!string.Equals(beforeUrl, afterUrl, StringComparison.Ordinal)) return "页面地址已变化";
            return action switch
            {
                BrowserProtocol.Actions.Inspect => "已刷新页面观察结果",
                BrowserProtocol.Actions.Screenshot => "已截取当前视口并同步页面观察结果",
                BrowserProtocol.Actions.Status => "已读取浏览器状态",
                _ => "操作完成；请根据新的页面版本和元素状态验证结果",
            };
        }

        private static JsonElement ParseBody(string body)
        {
            using var document = JsonDocument.Parse(string.IsNullOrWhiteSpace(body) ? "{}" : body);
            return document.RootElement.Clone();
        }

        private static string MergeAction(JsonElement request, string action)
        {
            var values = request.ValueKind == JsonValueKind.Object
                ? request.EnumerateObject().ToDictionary(property => property.Name, property => (object)property.Value.Clone(), StringComparer.Ordinal)
                : new Dictionary<string, object>(StringComparer.Ordinal);
            values["action"] = action;
            return JsonSerializer.Serialize(values);
        }

        private static string GetString(JsonElement element, string name)
        {
            if (element.ValueKind == JsonValueKind.Object && element.TryGetProperty(name, out var property))
            {
                return property.ValueKind == JsonValueKind.String ? property.GetString() ?? string.Empty : property.ToString();
            }
            return string.Empty;
        }

        private static int GetInt(JsonElement element, string name, int fallback)
        {
            return element.ValueKind == JsonValueKind.Object && element.TryGetProperty(name, out var property) && property.TryGetInt32(out var value)
                ? value
                : fallback;
        }

        private static long GetLong(JsonElement element, string name)
        {
            return element.ValueKind == JsonValueKind.Object && element.TryGetProperty(name, out var property) && property.TryGetInt64(out var value)
                ? value
                : 0L;
        }

        private static string[] GetStringArray(JsonElement element, string name)
        {
            if (element.ValueKind != JsonValueKind.Object || !element.TryGetProperty(name, out var property) || property.ValueKind != JsonValueKind.Array)
            {
                return Array.Empty<string>();
            }
            return property.EnumerateArray().Where(item => item.ValueKind == JsonValueKind.String).Select(item => item.GetString() ?? string.Empty).ToArray();
        }

        private static string DefaultString(string value, string fallback) => string.IsNullOrWhiteSpace(value) ? fallback : value;

        private static void Require(string value, string name)
        {
            if (string.IsNullOrWhiteSpace(value)) throw new ArgumentException("缺少 " + name + " 参数");
        }

        private static async Task Respond(HttpListenerResponse response, object body)
        {
            var json = JsonSerializer.Serialize(body, new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.CamelCase });
            var bytes = Encoding.UTF8.GetBytes(json);
            response.StatusCode = 200;
            response.ContentType = "application/json; charset=utf-8";
            response.ContentLength64 = bytes.Length;
            await response.OutputStream.WriteAsync(bytes);
            response.OutputStream.Close();
        }

    }
}
